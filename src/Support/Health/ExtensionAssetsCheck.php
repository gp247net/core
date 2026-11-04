<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Installed extensions with an `app/.../public` folder whose files never reached
 * `public/GP247/...` (installed from a folder before publishing existed).
 * Throws when the database cannot be read; callers treat that as "skipped".
 *
 * Doctor row `extension_assets` — detail text kept word for word.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004, US-CLI-extension-asset-repair
 * @aidlc-adr admin-shell_system-alert-registry
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
        $detail = count($missing) . ' installed extension(s) miss static files in public/: ' . implode(', ', $shown)
            . (count($missing) > self::SHOWN ? ' (+' . (count($missing) - self::SHOWN) . ' more)' : '')
            . ' — run "php artisan gp247:ext-publish --type=plugin|template --key=<Key>" (or --all)';

        return AdminAlert::warning(
            $this->key(),
            HealthText::t('admin.alerts.extension_assets', ['count' => count($missing)]),
            $detail,
            docUrl: HealthText::doc('security-updates-and-incidents'),
            items: $missing,
        );
    }

    /**
     * @return array<int, string> "<group>/<key>" of extensions missing public files.
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
                if (!is_file(public_path($relative . '/' . $file->getRelativePathname()))) {
                    $missing[] = $row->group . '/' . $row->key;
                    break;
                }
            }
        }

        return $missing;
    }
}
