<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use GP247\Core\Support\TemplateSourceAudit;

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

        // Where the active template's files actually come from, and how much of a
        // published copy is now dead weight shadowing the package
        // (US-CLI-template-source-lifecycle). Read-only.
        $checks[] = $this->templateSourceCheck();

        // PHP/Blade files that begin with a UTF-8 BOM. Read-only.
        $checks[] = $this->fileBomCheck();

        // Installed extensions whose static files never reached public/ (installed from
        // a folder before ExtensionInstaller::activate() published them). Read-only.
        $checks[] = $this->extensionAssetsCheck($installed);

        // Installed extensions whose files are newer than the version core recorded —
        // their data hook has not run yet (updated by git pull / composer / FTP). Read-only.
        $checks[] = $this->extensionDataPendingCheck($installed);

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
     * @param string        $detail Human detail.
     * @param array<int, string> $items  Optional itemised findings (added to the row only when non-empty, for --json).
     * @return array{name: string, status: string, detail: string, items?: array<int, string>}
     */
    protected function check(string $name, string $status, string $detail, array $items = []): array
    {
        $row = ['name' => $name, 'status' => $status, 'detail' => $detail];
        if ($items !== []) {
            $row['items'] = $items;
        }

        return $row;
    }

    /**
     * Report where a template's views are served from.
     *
     * A template's Blade lives in the package that ships it and is only copied
     * into app/GP247/Templates when the site publishes it to edit it. Files that
     * were published but never edited are invisible debt: they shadow the package
     * for ever, so no `composer update` can fix them. Count them and point at
     * gp247:template-prune.
     *
     * Reports cleanly when gp247/front is absent (core runs without it,
     * NFR-MAINT-001) and uses no gp247_* helper, because doctor is a
     * bootstrap-tier command that must work before the platform is installed.
     *
     * @return array{name: string, status: string, detail: string}
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-template-source-lifecycle
     * @aidlc-adr frontend-template-dev_template-vendor-resident-views
     */
    protected function templateSourceCheck(): array
    {
        if (empty(TemplateSourceAudit::roots(false))) {
            return $this->check('template_source', 'warn', 'no package provides template views (gp247/front not installed?)');
        }

        $stats = TemplateSourceAudit::stats();
        $identical = $stats['identical'];
        $edited = $stats['edited'];

        if ($identical > 0) {
            return $this->check(
                'template_source',
                'warn',
                $identical.' published file(s) are identical to the package copy and will never receive updates'
                    .($edited > 0 ? ', '.$edited.' customized' : '')
                    .' — run "php artisan gp247:template-prune <Template>" to hand the untouched ones back'
            );
        }

        return $this->check('template_source', 'pass', $edited > 0
            ? $edited.' customized file(s); everything else served from the package'
            : 'templates served from their package');
    }


    /**
     * Report PHP/Blade files that begin with a UTF-8 byte-order mark (EF BB BF).
     *
     * A BOM sits OUTSIDE the PHP tags, so PHP echoes it before anything else the
     * file produces. That breaks header()/redirect() with "headers already sent",
     * puts a stray character in front of JSON/XML/CSV responses, and prepends an
     * invisible character to a rendered view. Editors on Windows add it silently
     * when saving as UTF-8, and nothing about it shows when reading the file or
     * its diff — which is why a machine has to be the one looking.
     *
     * Reported as a warn, not a fail: it is a defect to clean up, not a reason to
     * refuse to install or to gate CI on a third-party plugin the owner cannot fix
     * today.
     *
     * Scans the site's own extensions and the GP247 packages — the code this
     * install actually runs. Uses no gp247_* helper, because doctor is a
     * bootstrap-tier command that must work before the platform is installed.
     *
     * @return array{name: string, status: string, detail: string}
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-004
     */
    protected function fileBomCheck(): array
    {
        $roots = array_filter([
            base_path('app/GP247'),
            base_path('vendor/gp247/core/src'),
            base_path('vendor/gp247/front/src'),
            base_path('vendor/gp247/shop/src'),
        ], 'is_dir');

        $bom = chr(0xEF).chr(0xBB).chr(0xBF);
        $found = [];

        try {
            foreach ($roots as $root) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($files as $file) {
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                        continue;
                    }

                    $handle = @fopen($file->getPathname(), 'rb');
                    if ($handle === false) {
                        continue;
                    }
                    $head = fread($handle, 3);
                    fclose($handle);

                    if ($head === $bom) {
                        // Reported relative to the project root, with forward
                        // slashes, so the path reads the same on every OS.
                        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                        $found[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
                    }
                }
            }
        } catch (\Throwable $e) {
            return $this->check('file_bom', 'warn', 'could not scan: '.$e->getMessage());
        }

        if ($found === []) {
            return $this->check('file_bom', 'pass', 'no file starts with a byte-order mark');
        }

        sort($found);
        $shown = array_slice($found, 0, 3);
        $more = count($found) - count($shown);

        return $this->check(
            'file_bom',
            'warn',
            count($found).' file(s) start with a UTF-8 BOM, which is echoed before any output: '
                .implode(', ', $shown)
                .($more > 0 ? ' (+'.$more.' more)' : '')
                .' — re-save each as UTF-8 WITHOUT BOM'
        );
    }

    /**
     * Report installed extensions whose public/ folder has files missing from
     * public/GP247/<type>/<Key> — the browser gets 404 for their CSS/JS/images.
     *
     * Happens on sites that installed an extension from a folder already on disk
     * (FTP upload, git clone, Docker image) before ExtensionInstaller::activate()
     * published static files. Reported as a warn: the site runs, the extension's
     * screens are broken. Each finding is "<type>/<Key>" in the row's items.
     *
     * WHY inline DB access instead of the extension "installed" helper: Doctor is
     * a bootstrap-tier command and must not call gp247_* helpers.
     *
     * @param bool $installed Whether the site is installed (skip otherwise).
     * @return array{name: string, status: string, detail: string, items?: array<int, string>}
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-extension-asset-repair
     * @aidlc-adr system-cli_service-extraction
     */
    /**
     * Extensions waiting for their data hook (US-PLG-local-update-data-converge).
     *
     * @param bool $installed Whether the site is installed.
     * @return array{name: string, status: string, detail: string, items?: array<int, string>}
     */
    protected function extensionDataPendingCheck(bool $installed): array
    {
        if (!$installed) {
            return $this->check('extension_data_pending', 'pass', 'skipped (not installed)');
        }
        try {
            $pending = (new \GP247\Core\Library\ExtensionDataUpdater)->pending();
        } catch (\Throwable $e) {
            return $this->check('extension_data_pending', 'pass', 'skipped (database unavailable)');
        }
        if ($pending === []) {
            return $this->check('extension_data_pending', 'pass', 'every installed extension is up to date');
        }
        $items = array_map(fn ($p) => $p['type'].'/'.$p['key'].' '.($p['from'] ?? 'unknown').' -> '.$p['to'], array_values($pending));

        return $this->check(
            'extension_data_pending',
            'warn',
            count($items).' extension(s) updated on disk but not in the database - run: php artisan gp247:ext-update --local --all (or gp247:update)',
            $items
        );
    }

    protected function extensionAssetsCheck(bool $installed): array
    {
        if (!$installed) {
            return $this->check('extension_assets', 'pass', 'skipped (not installed)');
        }

        try {
            $rows = DB::connection(GP247_DB_CONNECTION)
                ->table(GP247_DB_PREFIX.'admin_config')
                ->where('store_id', defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : '0')
                ->whereIn('group', ['Plugins', 'Templates'])
                ->get(['group', 'key']);
        } catch (\Throwable $e) {
            return $this->check('extension_assets', 'pass', 'skipped (database unavailable)');
        }

        $missing = [];
        foreach ($rows as $row) {
            $relative = 'GP247/'.$row->group.'/'.$row->key;
            $source = app_path($relative.'/public');
            if (preg_match('/^[A-Za-z0-9_-]+$/', (string) $row->key) !== 1 || !is_dir($source)) {
                continue;
            }
            foreach (File::allFiles($source) as $file) {
                if (!is_file(public_path($relative.'/'.$file->getRelativePathname()))) {
                    $missing[] = $row->group.'/'.$row->key;
                    break;
                }
            }
        }

        if ($missing === []) {
            return $this->check('extension_assets', 'pass', 'every installed extension has its static files in public/');
        }

        $shown = array_slice($missing, 0, 3);
        $detail = count($missing).' installed extension(s) miss static files in public/: '.implode(', ', $shown)
            .(count($missing) > 3 ? ' (+'.(count($missing) - 3).' more)' : '')
            .' — run "php artisan gp247:ext-publish --type=plugin|template --key=<Key>" (or --all)';

        return $this->check('extension_assets', 'warn', $detail, $missing);
    }

    /**
     * Verify every at-rest secret (admin_config.security = 1) still decrypts under the
     * current + previous APP_KEYs. A row that fails is the tell-tale of a changed
     * APP_KEY; report it — naming each broken row (group/key/store or table.column#id)
     * and its key id, never the value — so the owner knows which setting to recover.
     *
     * @param bool $installed Whether the site is installed (skip otherwise).
     * @return array{name: string, status: string, detail: string, items?: array<int, string>}
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-story US-CMP-secret-decrypt-diagnosable
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
        $broken = [];

        try {
            $connection = DB::connection(GP247_DB_CONNECTION);
            $schema = $connection->getSchemaBuilder();
            foreach ($columns as $table => $cols) {
                $fullTable = GP247_DB_PREFIX . $table;
                // A registered table that does not exist yet (feature not upgraded on this
                // site) holds no secret — skip it instead of failing the whole check.
                if (!$schema->hasTable($fullTable)) {
                    continue;
                }
                // Row identity for the report: config rows by group/key/store, any other
                // registered table by its id (never the value).
                $identity = $schema->hasColumns($fullTable, ['group', 'key', 'store_id'])
                    ? ['group', 'key', 'store_id']
                    : ($schema->hasColumn($fullTable, 'id') ? ['id'] : []);
                foreach ((array) $cols as $column) {
                    if (!$schema->hasColumn($fullTable, (string) $column)) {
                        continue;
                    }
                    $rows = $connection->table($fullTable)
                        ->where($column, 'like', 'enc:%')
                        ->get(array_merge($identity, [$column]));
                    foreach ($rows as $row) {
                        $value = (string) ($row->{$column} ?? '');
                        $total++;
                        if (!$this->canDecrypt($value)) {
                            $broken[] = $this->secretRowLabel((string) $table, (string) $column, $identity, $row, $value);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            return $this->check('secret_decryptable', 'pass', 'skipped (db unavailable)');
        }

        if ($broken !== []) {
            $shown = array_slice($broken, 0, 10);
            $more = count($broken) - count($shown);

            return $this->check(
                'secret_decryptable',
                'warn',
                count($broken) . ' of ' . $total . ' secrets undecryptable: ' . implode(', ', $shown)
                    . ($more > 0 ? ' …and ' . $more . ' more' : '')
                    . ' — the encryption key may have changed; set GP247_ENCRYPTION_PREVIOUS_KEYS / APP_PREVIOUS_KEYS or re-enter them',
                $broken
            );
        }

        return $this->check('secret_decryptable', 'pass', $total . ' secrets OK');
    }

    /**
     * Human label of an undecryptable secret row plus the key id it was written with:
     * "<group>/<key>@<store_id> kid=<kid>" for config rows, "<table>.<column>#<id> kid=<kid>"
     * otherwise ("v1" = legacy APP_KEY envelope). Never includes the value.
     *
     * @param string             $table    Unprefixed table.
     * @param string             $column   Secret column.
     * @param array<int, string> $identity Identity columns selected for the row.
     * @param object             $row      Query row.
     * @param string             $value    Raw enveloped value (only its kid is read).
     * @return string
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CMP-secret-decrypt-diagnosable
     */
    private function secretRowLabel(string $table, string $column, array $identity, object $row, string $value): string
    {
        if ($identity === ['group', 'key', 'store_id']) {
            $label = $row->group . '/' . $row->key . '@' . $row->store_id;
        } else {
            $label = $table . '.' . $column . ($identity === ['id'] ? '#' . $row->id : '');
        }

        $kid = 'v1';
        if (str_starts_with($value, 'enc:v2:')) {
            $rest = substr($value, strlen('enc:v2:'));
            $kid = substr($rest, 0, (int) strpos($rest, ':'));
        }

        return $label . ' kid=' . $kid;
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
