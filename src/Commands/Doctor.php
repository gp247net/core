<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Diagnose the environment before/after install: PHP version, required PHP
 * extensions, write permissions, DB connectivity and the installed marker.
 * Exits non-zero when any check fails, so it can gate CI/automation.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-004
 * @aidlc-adr system-cli_output-contract
 */
class Doctor extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247:doctor';

    /** @var string */
    protected $description = 'Check the environment for running/installing GP247';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $checks = [];

        // PHP version.
        $checks[] = $this->check(
            'php_version',
            version_compare(PHP_VERSION, '8.2.0', '>=') ? 'pass' : 'fail',
            PHP_VERSION
        );

        // Required PHP extensions (fail) and recommended ones (warn).
        foreach (['pdo', 'mbstring', 'openssl', 'tokenizer', 'ctype', 'json'] as $ext) {
            $checks[] = $this->check('ext_'.$ext, extension_loaded($ext) ? 'pass' : 'fail', extension_loaded($ext) ? 'loaded' : 'missing');
        }
        foreach (['zip', 'curl', 'gd', 'fileinfo'] as $ext) {
            $checks[] = $this->check('ext_'.$ext, extension_loaded($ext) ? 'pass' : 'warn', extension_loaded($ext) ? 'loaded' : 'missing');
        }

        // .env presence.
        $checks[] = $this->check('env_file', file_exists(base_path('.env')) ? 'pass' : 'fail', base_path('.env'));

        // Write permissions: required (fail) vs extension dirs (warn).
        $checks[] = $this->check('writable_storage', is_writable(storage_path()) ? 'pass' : 'fail', storage_path());
        $checks[] = $this->check('writable_bootstrap_cache', is_writable(base_path('bootstrap/cache')) ? 'pass' : 'fail', base_path('bootstrap/cache'));
        foreach (['app/GP247', 'public/GP247'] as $rel) {
            $abs = base_path($rel);
            $ok = is_dir($abs) ? is_writable($abs) : true; // absent dir is fine until first ext install
            $checks[] = $this->check('writable_'.str_replace('/', '_', strtolower($rel)), $ok ? 'pass' : 'warn', $abs);
        }

        // DB connectivity.
        try {
            DB::connection(GP247_DB_CONNECTION)->getPdo();
            $checks[] = $this->check('db_connection', 'pass', config('database.default'));
        } catch (\Throwable $e) {
            $checks[] = $this->check('db_connection', 'fail', $e->getMessage());
        }

        // Installed marker (informational).
        $installed = Storage::disk('local')->exists('gp247-installed.txt');
        $checks[] = $this->check('installed', $installed ? 'pass' : 'warn', $installed ? 'yes' : 'not installed');

        // Dedicated encryption key present? (warn = secrets are tied to APP_KEY).
        $checks[] = $this->check(
            'encryption_key_dedicated',
            (string) config('gp247-config.security.encryption_key', '') !== '' ? 'pass' : 'warn',
            (string) config('gp247-config.security.encryption_key', '') !== ''
                ? 'GP247_ENCRYPTION_KEY set'
                : 'not set — secrets use APP_KEY; set GP247_ENCRYPTION_KEY to insulate them from an APP_KEY change'
        );

        // Secret decryptability — the only visible signal that at-rest secrets died
        // (e.g. the key changed without keeping the old one). Read-only. Skipped when
        // the DB is unreachable or the site is not installed, so doctor stays usable as
        // the pre-install gate (ADR compat-foundation_config-secret-at-rest).
        $checks[] = $this->secretDecryptableCheck($installed);

        $hasFail = (bool) array_filter($checks, fn ($c) => $c['status'] === 'fail');

        if (!$this->isJson()) {
            $this->table(
                ['Check', 'Status', 'Detail'],
                array_map(fn ($c) => [$c['name'], strtoupper($c['status']), $c['detail']], $checks)
            );
        }

        if ($hasFail) {
            return $this->respondFailure('checks_failed', 'One or more environment checks failed', ['checks' => $checks]);
        }
        return $this->respondSuccess(['checks' => $checks]);
    }

    /**
     * Build a single check row.
     *
     * @param string $name   Check id.
     * @param string $status pass|warn|fail.
     * @param string $detail Human detail.
     * @return array{name: string, status: string, detail: string}
     */
    protected function check(string $name, string $status, string $detail): array
    {
        return ['name' => $name, 'status' => $status, 'detail' => $detail];
    }

    /**
     * Verify every at-rest secret (admin_config.security = 1) still decrypts under the
     * current + previous APP_KEYs. A row that fails is the tell-tale of a changed
     * APP_KEY; report it so the owner can restore APP_PREVIOUS_KEYS or re-enter the value.
     *
     * @param bool $installed Whether the site is installed (skip otherwise).
     * @return array{name: string, status: string, detail: string}
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    protected function secretDecryptableCheck(bool $installed): array
    {
        // WHY inline (no gp247_* helper): Doctor is a bootstrap-tier command whose
        // source must not reference gp247_* helpers (they do not exist pre-install) —
        // BootstrapCommandRegistrationTest enforces this. Envelope tags are literals.
        if (!$installed) {
            return $this->check('secret_decryptable', 'pass', 'skipped (not installed)');
        }

        $columns = (array) config('gp247-config.security.encrypted_columns', []);
        $total = 0;
        $failed = 0;

        try {
            $connection = DB::connection(GP247_DB_CONNECTION);
            foreach ($columns as $table => $cols) {
                foreach ((array) $cols as $column) {
                    $values = $connection->table(GP247_DB_PREFIX . $table)
                        ->where($column, 'like', 'enc:%')
                        ->pluck($column);
                    foreach ($values as $value) {
                        $total++;
                        if (!$this->canDecrypt((string) $value)) {
                            $failed++;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            return $this->check('secret_decryptable', 'pass', 'skipped (db unavailable)');
        }

        if ($failed > 0) {
            return $this->check(
                'secret_decryptable',
                'warn',
                $failed . ' of ' . $total . ' secrets undecryptable — the encryption key may have changed; set GP247_ENCRYPTION_PREVIOUS_KEYS / APP_PREVIOUS_KEYS or re-enter them'
            );
        }

        return $this->check('secret_decryptable', 'pass', $total . ' secrets OK');
    }

    /**
     * Attempt to decrypt one enveloped value (v1 = APP_KEY/Crypt, v2 = dedicated key).
     * Inlined (no gp247_* helper) to keep Doctor bootstrap-tier clean.
     *
     * @param string $value Raw stored value.
     * @return bool Whether it decrypted.
     */
    private function canDecrypt(string $value): bool
    {
        try {
            if (str_starts_with($value, 'enc:v2:')) {
                $rest = substr($value, strlen('enc:v2:'));
                $payload = substr($rest, strpos($rest, ':') + 1);
                $encrypter = $this->dedicatedEncrypter();
                if ($encrypter === null) {
                    return false;
                }
                $encrypter->decryptString($payload);

                return true;
            }
            if (str_starts_with($value, 'enc:v1:')) {
                Crypt::decryptString(substr($value, strlen('enc:v1:')));

                return true;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * Build an Encrypter over the dedicated key set (active + previous), or the APP_KEY
     * set when the dedicated key is unset. Inlined to avoid gp247_* helpers.
     *
     * @return \Illuminate\Encryption\Encrypter|null
     */
    private function dedicatedEncrypter(): ?Encrypter
    {
        $active = (string) config('gp247-config.security.encryption_key', '');
        if ($active !== '') {
            $previous = (array) config('gp247-config.security.encryption_previous_keys', []);
        } else {
            $active = (string) config('app.key', '');
            $previous = (array) config('app.previous_keys', []);
        }
        $parse = static function (string $k): string {
            $k = trim($k);
            return $k !== '' && str_starts_with($k, 'base64:') ? (string) base64_decode(substr($k, 7)) : $k;
        };
        $activeRaw = $parse($active);
        if ($activeRaw === '') {
            return null;
        }
        $encrypter = new Encrypter($activeRaw, (string) config('app.cipher', 'AES-256-CBC'));
        $prev = array_values(array_filter(array_map($parse, $previous)));
        if ($prev !== []) {
            $encrypter->previousKeys($prev);
        }

        return $encrypter;
    }
}
