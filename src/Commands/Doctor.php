<?php

namespace GP247\Core\Commands;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\Console\GP247Command;
use GP247\Core\Support\Health\ApiExposureCheck;
use GP247\Core\Support\Health\AppDebugCheck;
use GP247\Core\Support\Health\DefaultAdminPasswordCheck;
use GP247\Core\Support\Health\EncryptionKeyDedicatedCheck;
use GP247\Core\Support\Health\ExtensionAssetsCheck;
use GP247\Core\Support\Health\ExtensionDataPendingCheck;
use GP247\Core\Support\Health\HttpsCheck;
use GP247\Core\Support\Health\MfaEnabledCheck;
use GP247\Core\Support\Health\PasswordPolicyCheck;
use GP247\Core\Support\Health\SecretDecryptableCheck;
use GP247\Core\Support\Health\SupportLineCheck;
use GP247\Core\Support\Health\TrustedProxiesCheck;
use Illuminate\Support\Facades\DB;
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

    /** Lowest PHP the platform boots on — mirrors `php` in this package's composer.json (the only copy of this floor). */
    public const MIN_PHP_VERSION = '8.3.0';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $checks = [];

        // PHP version.
        $checks[] = $this->check(
            'php_version',
            version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=') ? 'pass' : 'fail',
            PHP_VERSION . ' (requires >= ' . self::MIN_PHP_VERSION . ')'
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
        $dbOk = false;
        try {
            DB::connection(GP247_DB_CONNECTION)->getPdo();
            $dbOk = true;
            $checks[] = $this->check('db_connection', 'pass', config('database.default'));
        } catch (\Throwable $e) {
            $checks[] = $this->check('db_connection', 'fail', $e->getMessage());
        }

        // Installed marker (informational).
        $installed = Storage::disk('local')->exists('gp247-installed.txt');
        $checks[] = $this->check('installed', $installed ? 'pass' : 'warn', $installed ? 'yes' : 'not installed');

        // Dedicated encryption key present? (warn = secrets are tied to APP_KEY). Config only,
        // so it runs before install too.
        $checks[] = $this->healthRow(new EncryptionKeyDedicatedCheck, 'skipped (check failed)');

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

        $checks[] = $this->apiConfigLegacyNamesCheck();

        // Cached API routes built before the API switch gate existed (site ran composer
        // update but not gp247:update, which rebuilds the cache). Read-only.
        $checks[] = $this->apiRouteGateCheck();

        // Event log for ERP sync whose lazy cleaning does not run (no requests). Read-only.
        $checks[] = $this->apiEventRetentionCheck();

        // Tasks left by a post-incident restore (US-backup-restore-post-incident-mode): shown
        // until an Administrator marks them done on the backup screen. Never `fail`.
        $checks[] = $this->backupPostIncidentCheck($dbOk);

        // Backup area (US-backup-admin-doctor-checks): set up, newest verified backup, schedule,
        // folder reachable over the web (the only one that may `fail`), destination, tables
        // outside every data group, mail. Feature off ⇒ one `pass` row.
        if ($installed && $dbOk) {
            array_push($checks, ...(new \GP247\Core\Backup\Application\BackupDoctor)->rows());
        }

        // Security rows shared with the dashboard "System alerts" block (one source of logic).
        // Never `fail` — the exit code stays driven by the environment checks above.
        array_push($checks, ...$this->securityChecks($installed, $dbOk));

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
     * Tasks a post-incident restore left to the site owner, until marked done.
     *
     * @param bool $dbOk The database answered.
     * @return array{name: string, status: string, detail: string, items?: array<int, string>}
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    protected function backupPostIncidentCheck(bool $dbOk): array
    {
        $pending = $dbOk ? \GP247\Core\Backup\Application\PostIncidentChecklist::pending() : null;
        if ($pending === null) {
            return $this->check('backup_post_incident_pending', 'pass', 'none');
        }

        return $this->check('backup_post_incident_pending', 'warn', 'after the post-incident restore of ' . (string) ($pending['at'] ?? '')
            . ': put a new GP247_ENCRYPTION_KEY in .env (old one to GP247_ENCRYPTION_PREVIOUS_KEYS), run php artisan gp247:encryption-key-rotate, reinstall the code, change database / hosting passwords, then mark it done on the backup screen',
            array_map('strval', \GP247\Core\Backup\Application\PostIncidentChecklist::TASKS));
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
            base_path('vendor/gp247/mcp/src'),
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
     * REST API environment names still using the old misspelled form (they keep working;
     * rename them in .env to the name in brackets).
     *
     * @return array{name: string, status: string, detail: string, items?: array<int, string>}
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-api-config-naming
     */
    protected function apiConfigLegacyNamesCheck(): array
    {
        if (app()->configurationIsCached()) {
            // WHY: with config cached Laravel does not read .env, so env() cannot see old names.
            return $this->check('api_config_legacy_names', 'pass', 'skipped (config cached - run config:clear to check)');
        }
        $legacy = \GP247\Core\Api\Support\ApiEnv::legacyInUse();
        if (!$legacy) {
            return $this->check('api_config_legacy_names', 'pass', 'none');
        }
        $renamed = array_flip(\GP247\Core\Api\Support\ApiEnv::RENAMED);
        $items = array_map(fn ($old) => $old . ' (' . $renamed[$old] . ')', $legacy);

        return $this->check('api_config_legacy_names', 'warn', count($legacy) . ' legacy name(s) in .env', $items);
    }

    /**
     * With routes cached, every REST API route registered through the core seam must
     * carry the whole shared API chain (GP247_API_MIDDLEWARE: request id + switch gate);
     * a cache built by older code lacks them, so the switches on the API screen would do
     * nothing until the cache is rebuilt.
     *
     * @return array{name: string, status: string, detail: string, items?: array<int, string>}
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CMP-api-admin-switch
     */
    protected function apiRouteGateCheck(): array
    {
        if (!app()->routesAreCached()) {
            return $this->check('api_route_gate', 'pass', 'routes not cached');
        }
        $chain = defined('GP247_API_MIDDLEWARE') ? GP247_API_MIDDLEWARE : [];
        $missing = [];
        foreach (app('router')->getRoutes() as $route) {
            $middleware = $route->middleware();
            if (in_array('api.extend', $middleware, true) && array_diff($chain, $middleware) !== []) {
                $missing[] = $route->uri();
            }
        }
        if (!$missing) {
            return $this->check('api_route_gate', 'pass', 'cached API routes carry the shared API middleware');
        }

        return $this->check(
            'api_route_gate',
            'warn',
            count($missing) . ' cached API route(s) without the switch gate - run php artisan gp247:cache-rebuild',
            array_slice(array_values(array_unique($missing)), 0, 20)
        );
    }

    /**
     * The event log keeps events GP247_API_EVENT_DAYS days and cleans them during requests;
     * events older than that by two days mean the cleaning does not run. Read-only.
     *
     * @return array
     *
     * @aidlc-unit api-sync
     * @aidlc-story US-api-sync-event-retention
     */
    protected function apiEventRetentionCheck(): array
    {
        try {
            if (!\GP247\Core\Api\Sync\ApiEvents::ready()) {
                return $this->check('api_event_retention', 'pass', 'skipped (no event table - run php artisan gp247:update)');
            }
            $days = \GP247\Core\Api\Sync\EventPruner::keepDays();
            $stale = \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_event')
                ->where('occurred_at', '<', now()->subDays($days + 2))->count();
        } catch (\Throwable $e) {
            return $this->check('api_event_retention', 'pass', 'skipped (database unavailable)');
        }
        if ($stale === 0) {
            return $this->check('api_event_retention', 'pass', 'no event older than ' . $days . ' days');
        }

        return $this->check('api_event_retention', 'warn', $stale . ' event(s) older than ' . $days . ' days - cleaning runs on API requests; read GET api/v2/core/events or lower GP247_API_EVENT_DAYS');
    }

    /**
     * Extensions waiting for their data hook (US-PLG-local-update-data-converge).
     * Logic lives in the shared check class (NFR-MAINT-health-check-single-source).
     *
     * @param bool $installed Whether the site is installed.
     * @return array{name: string, status: string, detail: string, items?: array<int, string>}
     */
    protected function extensionDataPendingCheck(bool $installed): array
    {
        if (!$installed) {
            return $this->check('extension_data_pending', 'pass', 'skipped (not installed)');
        }

        return $this->healthRow(new ExtensionDataPendingCheck, 'skipped (database unavailable)');
    }

    /**
     * Installed extensions whose static files never reached public/ (US-CLI-extension-asset-repair).
     *
     * @param bool $installed Whether the site is installed.
     * @return array{name: string, status: string, detail: string, items?: array<int, string>}
     */
    protected function extensionAssetsCheck(bool $installed): array
    {
        if (!$installed) {
            return $this->check('extension_assets', 'pass', 'skipped (not installed)');
        }

        return $this->healthRow(new ExtensionAssetsCheck, 'skipped (database unavailable)');
    }

    /**
     * Every at-rest secret still decrypts under the current + previous keys; broken rows
     * are named (never their value).
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
        if (!$installed) {
            return $this->check('secret_decryptable', 'pass', 'skipped (not installed)');
        }

        return $this->healthRow(new SecretDecryptableCheck, 'skipped (db unavailable)');
    }

    /**
     * Security rows shared with the "System alerts" dashboard block. Only on an installed
     * site with a reachable database (they read admin_config / admin_user).
     *
     * @param bool $installed Whether the site is installed.
     * @param bool $dbOk      Whether the database connection works.
     * @return array<int, array{name: string, status: string, detail: string, items?: array<int, string>}>
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-004
     * @aidlc-adr admin-shell_system-alert-registry
     */
    protected function securityChecks(bool $installed, bool $dbOk): array
    {
        if (!$installed || !$dbOk) {
            return [];
        }

        $rows = [];
        foreach ([
            new AppDebugCheck,
            new HttpsCheck,
            new TrustedProxiesCheck,
            new DefaultAdminPasswordCheck,
            new PasswordPolicyCheck,
            new MfaEnabledCheck,
            new ApiExposureCheck,
            new SupportLineCheck,
        ] as $check) {
            $rows[] = $this->healthRow($check, 'skipped (check failed)');
        }

        return $rows;
    }

    /**
     * Turn a shared check result into a doctor row. Mapping: critical/warning ⇒ warn,
     * info/ok ⇒ pass — never `fail`, so adding these rows cannot change the exit code.
     *
     * @param AdminAlertCheck $check
     * @param string          $failedDetail Detail printed when the check throws (e.g. DB unreadable).
     * @return array{name: string, status: string, detail: string, items?: array<int, string>}
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-004
     * @aidlc-adr admin-shell_system-alert-registry
     */
    private function healthRow(AdminAlertCheck $check, string $failedDetail): array
    {
        try {
            $alert = $check->evaluate();
        } catch (\Throwable $e) {
            return $this->check($check->key(), 'pass', $failedDetail);
        }

        $status = in_array($alert->level, [AdminAlert::CRITICAL, AdminAlert::WARNING], true) ? 'warn' : 'pass';

        return $this->check($alert->key, $status, $alert->detail, $alert->items);
    }
}
