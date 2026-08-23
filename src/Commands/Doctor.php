<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
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
}
