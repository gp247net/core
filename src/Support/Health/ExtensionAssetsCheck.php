<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Installed extensions with an `app/.../public` folder whose files never reached
 * `public/GP247/...` (installed from a folder before publishing existed), or reached it in
 * an older build (files copied over app/ without publishing — mod 20261117T091216).
 * Throws when the database cannot be read; callers treat that as "skipped".
 *
 * Doctor row `extension_assets` — detail text kept word for word.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004, US-CLI-extension-asset-repair
 * @aidlc-adr admin-shell_system-alert-registry
 * @aidlc-adr plugin-manager_extension-update-fresh-process
 */
class ExtensionAssetsCheck implements AdminAlertCheck
{
    private const SHOWN = 3;

    /** @return string */
    public function key(): string
    {
        return 'extension_assets';
    }

    /** @return int */
    public function ttl(): int
    {
        return 600;
    }

    /**
     * @return AdminAlert
     */
    public function evaluate(): AdminAlert
    {
        $missing = $this->missing();
        if ($missing === []) {
            return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.extension_assets_ok'), 'every installed extension has its static files in public/');
        }

        $shown = array_slice($missing, 0, self::SHOWN);
        $detail = count($missing) . ' installed extension(s) have missing or outdated static files in public/: ' . implode(', ', $shown)
            . (count($missing) > self::SHOWN ? ' (+' . (count($missing) - self::SHOWN) . ' more)' : '')
            . ' — run "php artisan gp247:ext-publish --type=plugin|template --key=<Key>" (or --all)';

        return AdminAlert::warning(
            $this->key(),
            HealthText::t('admin.alerts.extension_assets_stale', ['count' => count($missing)]),
            $detail,
            docUrl: HealthText::doc('security-updates-and-incidents'),
            items: $missing,
        );
    }

    /**
     * @return array<int, string> "<group>/<key>" of extensions whose public files are missing or outdated.
     */
    private function missing(): array
    {
        $rows = DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX . 'admin_config')
            ->where('store_id', defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : '0')
            ->whereIn('group', ['Plugins', 'Templates'])
            ->get(['group', 'key']);

        $missing = [];
        foreach ($rows as $row) {
            $relative = 'GP247/' . $row->group . '/' . $row->key;
            $source = app_path($relative . '/public');
            // WHY: the key becomes a path segment — only plain extension keys are inspected.
            if (preg_match('/^[A-Za-z0-9_-]+$/', (string) $row->key) !== 1 || !is_dir($source)) {
                continue;
            }
            foreach (File::allFiles($source) as $file) {
                if (!$this->sameFile($file->getPathname(), public_path($relative . '/' . $file->getRelativePathname()))) {
                    $missing[] = $row->group . '/' . $row->key;
                    break;
                }
            }
        }

        return $missing;
    }

    /**
     * Is the published copy present and identical to the source?
     *
     * WHY size first: hashing is only needed when sizes match, which keeps the check cheap.
     *
     * @param string $source    File under app/GP247/<type>/<Key>/public.
     * @param string $published Its copy under public/GP247/<type>/<Key>.
     * @return bool
     */
    private function sameFile(string $source, string $published): bool
    {
        if (!is_file($published)) {
            return false;
        }
        if (filesize($source) !== filesize($published)) {
            return false;
        }

        return hash_file('sha256', $source) === hash_file('sha256', $published);
    }
}
