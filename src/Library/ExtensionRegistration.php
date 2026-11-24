<?php

namespace GP247\Core\Library;

use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminHome;
use Illuminate\Support\Facades\DB;

/**
 * The records core itself keeps for an installed extension — as opposed to the data the
 * extension's own install() created (tables, menus, language rows, settings, license),
 * which only the extension's uninstall() can know about.
 *
 * Used to "forget" an orphaned extension (flag row in the database, source gone) so it
 * can be installed again like a new one, without guessing at the extension's own data
 * (ADR plugin-manager_orphan-forget-registration).
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-orphan-forget-registration
 * @aidlc-adr plugin-manager_orphan-forget-registration
 */
class ExtensionRegistration
{
    /**
     * English fallback of admin.extension.orphan_source_missing, for a site that has not run
     * gp247:update since this text changed (same text as DataLanguageSeeder).
     */
    public const ORPHAN_NOTICE_EN = '":key" is installed in the database (data version :version) but this site has no source for it. GP247 does not install over existing data. Way 1: copy app/GP247/:type/:key and public/GP247/:type/:key of the same version or a newer one (the newer version must allow updating from :version), then click "Apply data update" on the :type screen or run "php artisan gp247:update". If you can only copy app/, run "php artisan gp247:ext-publish --type=:cli_type --key=:key". Way 2: if you no longer need it, click "Forget" on the :type screen or run "php artisan gp247:ext-uninstall --type=:cli_type --key=:key --forget".';

    /**
     * Keys of the rows of a group that register an extension.
     *
     * gp247_extension_get_installed() returns every row of the group, and some extensions keep
     * more than their flag row there: core's recorded version (code "extension_version") and
     * settings rows "<Key>_<setting>" (code "<Key>_config", as some payment plugins do). Those
     * have no folder of their own, so they must never be taken for a missing extension — and
     * a settings row must never be "forgotten" (it would delete a real setting).
     *
     * @param string $groupType Plugins|Templates (already normalized by the caller).
     * @return string[]
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-orphan-forget-registration
     */
    public static function registeredKeys(string $groupType): array
    {
        $codes = [];
        foreach (gp247_extension_get_installed(type: $groupType, active: false) as $key => $row) {
            $codes[(string) $key] = (string) ($row->code ?? '');
        }

        $keys = [];
        foreach ($codes as $key => $code) {
            if ($code === 'extension_version' || str_ends_with($code, '_config') || !ExtensionInstaller::isValidKey($key)
                || self::belongsToAnother($key, $codes)) {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Whether a key is a "<OtherKey>_..." row of another key of the same group.
     *
     * @param string               $key
     * @param array<string,string> $codes Every key of the group => its code.
     * @return bool
     */
    private static function belongsToAnother(string $key, array $codes): bool
    {
        foreach (array_keys($codes) as $other) {
            if ($other !== $key && str_starts_with($key, $other . '_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the registration records of an extension, atomically.
     *
     * Removes: the flag rows of every store (admin_config group=<type>, key=<Key>), the
     * recorded installed version, the dashboard blocks tagged with the extension path, and
     * the marker of a library update left half-done. Keeps everything else.
     *
     * @param string $groupType Plugins|Templates (already normalized by the caller).
     * @param string $key       Extension key (already validated by the caller).
     * @return void
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-orphan-forget-registration
     */
    public static function forget(string $groupType, string $key): void
    {
        DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($groupType, $key) {
            AdminConfig::where('group', $groupType)->where('key', $key)->delete();
            gp247_extension_forget_installed_version($groupType, $key);
            // WHY this path: AppConfig::$appPath ("<type>/<Key>") is what extensions write into
            // admin_home.extension and what their own uninstall() deletes by.
            AdminHome::where('extension', $groupType . '/' . $key)->delete();
        });
        // WHY after the commit: a file, not a row — it must only go once the rows are gone.
        ExtensionPendingUpdate::forget($groupType, $key);
    }
}
