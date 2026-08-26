<?php
use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminHome;
use Illuminate\Support\Facades\Artisan;

if (!function_exists('gp247_extension_version_in_required_core_ranges') && !in_array('gp247_extension_version_in_required_core_ranges', config('gp247_functions_except', []))) {
    /**
     * Check current core version is within any required ranges.
     * A required item like "1.2" means: >= 1.2.0 and < 2.0.0
     * Multiple items mean union of ranges.
     */
    function gp247_extension_version_in_required_core_ranges(array $requireCore, string $currentVersion): bool
    {
        $normalize = function ($version) {
            $version = trim((string) $version);
            if ($version === '') {
                return '0.0.0';
            }
            $parts = explode('.', $version);
            $parts = array_slice(array_merge($parts, ['0', '0', '0']), 0, 3);
            return implode('.', $parts);
        };

        $current = $normalize($currentVersion);

        foreach ($requireCore as $start) {
            if (!is_string($start) || $start === '') {
                continue;
            }
            $startNorm = $normalize($start);
            $major = (int) explode('.', $startNorm)[0];
            $upper = ($major + 1) . '.0.0';

            if (version_compare($current, $startNorm, '>=') && version_compare($current, $upper, '<')) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('gp247_extension_get_all_local') && !in_array('gp247_extension_get_all_local', config('gp247_functions_except', []))) {
    /**
     * Get all extension local
     *
     * @param   [string]  $code  Payment, Shipping
     *
     * @return  [array]
     */
    function gp247_extension_get_all_local($type = 'Plugins')
    {
        if ($type == 'Templates') {
            $typeTmp = 'Templates';
        } else {
            $typeTmp = 'Plugins';
        }
        $arrClass = [];
        $dirs = array_filter(glob(app_path() . '/GP247/'.$typeTmp.'/*'), 'is_dir');
        if ($dirs) {
            foreach ($dirs as $dir) {
                $tmp = explode('/', $dir);
                $nameSpace = '\App\GP247\\' . $typeTmp.'\\'.end($tmp);
                if (file_exists($dir . '/AppConfig.php')) {
                    $arrClass[end($tmp)] = $nameSpace;
                }
            }
        }
        return $arrClass;
    }
}

if (!function_exists('gp247_extension_get_installed') && !in_array('gp247_extension_get_installed', config('gp247_functions_except', []))) {
    /**
     * Get all class plugin
     *
     *
     */
    function gp247_extension_get_installed($type = "Plugins", $active = true)
    {
        switch ($type) {
            case 'Templates':
                return \GP247\Core\Models\AdminConfig::getTemplateCode($active);
                break;
            case 'Plugins':
                return \GP247\Core\Models\AdminConfig::getPluginCode($active);
                break;
            default:
                return \GP247\Core\Models\AdminConfig::getExtensionCode($active);
                break;
        }
    }
}


    /**
     * Get namespace extension config
     *
     *
     * @return  [array]
     */
    if (!function_exists('gp247_extension_get_namespace') && !in_array('gp247_extension_get_namespace', config('gp247_functions_except', []))) {
        function gp247_extension_get_namespace(string $type="Plugins", $key = null)
        {
            if (is_null($key)) {
                return null;
            }
            $type = $type == 'Templates' ? 'Templates' : 'Plugins';
            $key = gp247_word_format_class($key);
            $nameSpace = '\App\GP247\\' . $type . '\\' . $key;
            return $nameSpace;
        }
    }

    /**
     * Check plugin and template compatibility with GP247 version
     *
     * @param   array  $config  [$versionsConfig description]
     *
     * @return  [type]                   [return description]
     */
    if (!function_exists('gp247_extension_check_compatibility') && !in_array('gp247_extension_check_compatibility', config('gp247_functions_except', []))) {
        function gp247_extension_check_compatibility(array $config) {
            $arrRequireFaild = [];

            $requireCore = $config['requireCore'] ?? [];

            // Manifest dependency keys were renamed for clarity in gp247/core 2.1:
            //   requirePackages   -> requireComposerPackages (Composer/packagist packages)
            //   requireExtensions -> requireGp247Extensions  (installed GP247 plugins/templates)
            // WHY: core is the single point that keeps backward compatibility for third-party
            // manifests still shipping the old keys — read the new key first, fall back to the
            // old one, and warn so authors migrate. All GP247-owned manifests use the new keys.
            $requireComposerPackages = $config['requireComposerPackages'] ?? $config['requirePackages'] ?? [];
            $requireGp247Extensions  = $config['requireGp247Extensions'] ?? $config['requireExtensions'] ?? [];

            if (array_key_exists('requirePackages', $config) || array_key_exists('requireExtensions', $config)) {
                logger()->warning('[gp247.json deprecated] "requirePackages"/"requireExtensions" are deprecated since gp247/core 2.1; '
                    . 'use "requireComposerPackages"/"requireGp247Extensions" instead. They will be removed in a future release.');
            }

            if($requireCore) {
                // Check core version gp247 by ranges
                $currentCore = config('gp247.core') ?? (gp247_composer_get_package_installed()['gp247/core'] ?? null);
                if (!$currentCore || !gp247_extension_version_in_required_core_ranges($requireCore, $currentCore)) {
                    $arrRequireFaild['requireCore'] = $requireCore;
                }
            }

            if($requireComposerPackages) {
                //Check package composer
                $listPackages = gp247_composer_get_package_installed();
                foreach($requireComposerPackages as $package) {
                    if(!in_array($package, array_keys($listPackages))) {
                        $arrRequireFaild['requireComposerPackages'][] = $package;
                    }
                }
            }

            if($requireGp247Extensions) {
                //Check extension installed (plugin or template)
                $listExtensionsInstalled = gp247_extension_get_installed(type: 'Extension');
                if (count($listExtensionsInstalled)) {
                    $listExtensionsInstalled = $listExtensionsInstalled->toArray();
                } else {
                    $listExtensionsInstalled = [];
                }
                foreach($requireGp247Extensions as $extension) {
                    if(!in_array($extension, array_keys($listExtensionsInstalled))) {
                        $arrRequireFaild['requireGp247Extensions'][] = $extension;
                    }
                }
            }

            return $arrRequireFaild;
        }
    }


if (!function_exists('gp247_extension_source_exists') && !in_array('gp247_extension_source_exists', config('gp247_functions_except', []))) {
    /**
     * Check the extension source code is present on disk.
     *
     * DB config (admin_config) and the filesystem (app/GP247/<type>/<key>/) drift
     * independently — DB migrated from another environment, folder removed over
     * FTP, incomplete deploy. "Active" must therefore mean config flag AND
     * loadable code; this predicate is the single definition of "code present",
     * using the same disk signal as gp247_extension_get_all_local() (AppConfig.php).
     *
     * The result is memoized per request: at most one file_exists() per group|key.
     *
     * @param string $group Plugins|Templates (anything else is treated as Plugins).
     * @param string $key   Extension key (folder name).
     * @return bool True when app/GP247/<type>/<key>/AppConfig.php exists.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-active-requires-source
     * @aidlc-adr plugin-manager_active-check-source-presence
     */
    function gp247_extension_source_exists($group, $key)
    {
        static $memo = [];

        $type = $group === 'Templates' ? 'Templates' : 'Plugins';
        $cacheKey = $type . '|' . $key;
        if (!array_key_exists($cacheKey, $memo)) {
            $memo[$cacheKey] = file_exists(app_path('GP247/' . $type . '/' . $key . '/AppConfig.php'));
        }

        return $memo[$cacheKey];
    }
}

if (!function_exists('gp247_extension_check_active') && !in_array('gp247_extension_check_active', config('gp247_functions_except', []))) {
    /**
     * Check an extension is active: DB flag on AND source present on disk.
     *
     * For group Plugins/Templates an orphaned record (DB active, source missing)
     * soft-degrades: returns false and reports via gp247_report() — WITHOUT mutating the DB
     * (this runs on every front request; auto-deactivating on the read path would
     * race between instances and hide the drift from the admin). Other groups
     * keep the DB-only behavior.
     *
     * @param string $group Config group (Plugins, Templates, ...).
     * @param string $key   Extension key.
     * @return bool
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-active-requires-source
     * @aidlc-adr plugin-manager_active-check-source-presence
     */
    function gp247_extension_check_active($group, $key)
    {
        $checkConfig = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)
        ->where('key', $key)
        ->where('group', $group)
        ->where('value', 1)
        ->first();

        if (!$checkConfig) {
            return false;
        }

        if (in_array($group, ['Plugins', 'Templates'], true) && !gp247_extension_source_exists($group, $key)) {
            // WHY: report once per request per extension — this helper is called from
            // layouts/menus many times per page; repeating the same line is log spam.
            static $warned = [];
            if (!isset($warned[$group . '|' . $key])) {
                $warned[$group . '|' . $key] = true;
                gp247_report('[gp247 extension] "' . $group . '/' . $key . '" is active in DB but its source is missing on disk (app/GP247/' . $group . '/' . $key . '). Treated as inactive — reinstall the extension or clean up the orphaned config record.');
            }
            return false;
        }

        return true;
    }
}

if (!function_exists('gp247_extension_check_installed') && !in_array('gp247_extension_check_installed', config('gp247_functions_except', []))) {

    // Check extension is installed
    function gp247_extension_check_installed($group, $key)
    {
        $checkConfig = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)
        ->where('key', $key)
        ->where('group', $group)
        ->first();

        if ($checkConfig) {
            return true;
        } else {
            return false;
        }
    }
}


if (!function_exists('gp247_extension_after_update') && !in_array('gp247_extension_after_update', config('gp247_functions_except', []))) {

    /**
     * Invalidate framework caches after an extension (template or plugin) lifecycle
     * change (install / enable / disable / uninstall / update). Also the body of the
     * `gp247:cache-rebuild` command and the last step of `gp247:update`.
     *
     * Rebuilds route and config caches only when the site already opted into them
     * (their cache file exists) — never forcing caching on a site that runs uncached.
     * Always clears the compiled Blade views so freshly published views take effect.
     *
     * Runs entirely under try/catch with soft-degrade (`gp247_report`) so a restricted
     * shared host that cannot write these caches never turns a lifecycle action fatal.
     *
     * @return void
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-003
     * @aidlc-adr system-cli_cache-rebuild-scope
     */
    function gp247_extension_after_update()
    {
        try {
            // WHY: detect the route/config cache files via Laravel's own accessors instead
            // of a hardcoded name (`routes-v7.php`/`config.php`). The route-cache filename
            // carries a framework format-version suffix (v6 -> v7 historically) and both
            // paths honor the APP_ROUTES_CACHE / APP_CONFIG_CACHE env overrides; a literal
            // name silently stops matching after a framework bump, leaving stale caches.
            if (file_exists(app()->getCachedRoutesPath())) {
                Artisan::call('route:clear');
                Artisan::call('route:cache');
            }

            if (file_exists(app()->getCachedConfigPath())) {
                Artisan::call('config:clear');
                Artisan::call('config:cache');
            }

            // WHY: clear compiled Blade unconditionally. Unlike route/config, `view:clear`
            // only deletes regenerated-on-demand files (storage/framework/views/*.php) — it
            // never creates a persistent cache the site did not opt into. Blade's mtime-based
            // recompile misses a newly published view whose mtime is <= the stale compiled
            // file (tarball checkout keeping old timestamps, clock skew in containers), so an
            // explicit clear is required for `--publish` and every extension flow to take effect.
            Artisan::call('view:clear');
        } catch (\Throwable $e) {
            gp247_report($e->getMessage());
        }
    }
}


if (!function_exists('gp247_extension_get_via_code') && !in_array('gp247_extension_get_via_code', config('gp247_functions_except', []))) {
    /**
     * Get all class plugin actived
     *
     * Orphaned plugins (DB record active but source missing on disk) are skipped
     * with a warning — a missing Payment/Shipping/Total class would otherwise be
     * instantiated later and turn checkout fatal.
     *
     * @param   [string]  $code  Payment, Shipping
     * @param   [boolean]  $active  true, false
     *
     * @return  [array]
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-active-requires-source
     * @aidlc-adr plugin-manager_active-check-source-presence
     */
    function gp247_extension_get_via_code(string $code, bool $active = true)
    {
        $code = gp247_word_format_class($code);

        $pluginsActived = [];
        $allPlugins = gp247_extension_get_installed(type: 'Plugins', active: $active);
        if (count($allPlugins)) {
            foreach ($allPlugins as $keyPlugin => $plugin) {
                if (gp247_config($keyPlugin) == 1 && $plugin['code'] == $code) {
                    if (!gp247_extension_source_exists('Plugins', $keyPlugin)) {
                        gp247_report('[gp247 extension] Plugin "' . $keyPlugin . '" (code ' . $code . ') is active in DB but its source is missing on disk. Skipped.');
                        continue;
                    }
                    $pluginsActived[$keyPlugin] = $plugin;
                }
            }
        }
        return $pluginsActived;
    }
}


if (!function_exists('gp247_extension_get_license') && !in_array('gp247_extension_get_license', config('gp247_functions_except', []))) {
    /**
     * Get the per-plugin license stored for a paid extension.
     *
     * This is the license tied to the plugin purchase (order), stored in
     * admin_config under group 'ExtensionLicense' — distinct from the
     * API-connection license (GP247_API_LICENSE in .env).
     *
     * @param string $type Plugins|Templates.
     * @param string $key  Extension key.
     * @return string The stored license key, or '' when none.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     * @aidlc-adr plugin-manager_per-plugin-license
     */
    function gp247_extension_get_license(string $type, string $key): string
    {
        $type = $type === 'Templates' ? 'Templates' : 'Plugins';
        $row = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)
            ->where('group', 'ExtensionLicense')
            ->where('key', $type.'.'.$key)
            ->first();
        return $row->value ?? '';
    }
}


if (!function_exists('gp247_extension_save_license') && !in_array('gp247_extension_save_license', config('gp247_functions_except', []))) {
    /**
     * Persist the per-plugin license for a paid extension.
     *
     * @param string $type    Plugins|Templates.
     * @param string $key     Extension key.
     * @param string $license License key entered by the admin.
     * @return void
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     * @aidlc-adr plugin-manager_per-plugin-license
     */
    function gp247_extension_save_license(string $type, string $key, string $license): void
    {
        $type = $type === 'Templates' ? 'Templates' : 'Plugins';
        $row = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)
            ->where('group', 'ExtensionLicense')
            ->where('key', $type.'.'.$key)
            ->first();
        // Set attributes individually to avoid mass-assignment concerns.
        if (!$row) {
            $row = new AdminConfig();
            $row->store_id = GP247_STORE_ID_GLOBAL;
            $row->group = 'ExtensionLicense';
            $row->code = 'license';
            $row->key = $type.'.'.$key;
            $row->sort = 0;
        }
        $row->value = trim($license);
        $row->save();
    }
}


if (!function_exists('gp247_extension_set_license_status') && !in_array('gp247_extension_set_license_status', config('gp247_functions_except', []))) {
    /**
     * Persist the server-verified status of a per-plugin license.
     *
     * Stored as JSON in the admin_config `detail` column of the license row so
     * the admin UI can flag an invalid/expired key without re-calling the API.
     *
     * @param string $type   Plugins|Templates.
     * @param string $key    Extension key.
     * @param array  $status {valid:bool, reason:string, expire:?string, checked:bool}.
     * @return void
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     * @aidlc-adr plugin-manager_per-plugin-license
     */
    function gp247_extension_set_license_status(string $type, string $key, array $status): void
    {
        $type = $type === 'Templates' ? 'Templates' : 'Plugins';
        $row = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)
            ->where('group', 'ExtensionLicense')
            ->where('key', $type.'.'.$key)
            ->first();
        if (!$row) {
            return;
        }
        $row->detail = json_encode($status);
        $row->save();
    }
}


if (!function_exists('gp247_extension_get_license_status') && !in_array('gp247_extension_get_license_status', config('gp247_functions_except', []))) {
    /**
     * Read the stored verification status of a per-plugin license.
     *
     * @param string $type Plugins|Templates.
     * @param string $key  Extension key.
     * @return array Decoded status, or [] when none stored.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     * @aidlc-adr plugin-manager_per-plugin-license
     */
    function gp247_extension_get_license_status(string $type, string $key): array
    {
        $type = $type === 'Templates' ? 'Templates' : 'Plugins';
        $row = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)
            ->where('group', 'ExtensionLicense')
            ->where('key', $type.'.'.$key)
            ->first();
        if (!$row || !$row->detail) {
            return [];
        }
        $decoded = json_decode($row->detail, true);
        return is_array($decoded) ? $decoded : [];
    }
}


if (!function_exists('gp247_extension_delete_license') && !in_array('gp247_extension_delete_license', config('gp247_functions_except', []))) {
    /**
     * Remove the stored per-plugin license row entirely (value + status).
     *
     * Used when the admin clears the license input — no empty row is left behind.
     *
     * @param string $type Plugins|Templates.
     * @param string $key  Extension key.
     * @return void
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     * @aidlc-adr plugin-manager_per-plugin-license
     */
    function gp247_extension_delete_license(string $type, string $key): void
    {
        $type = $type === 'Templates' ? 'Templates' : 'Plugins';
        AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)
            ->where('group', 'ExtensionLicense')
            ->where('key', $type.'.'.$key)
            ->delete();
    }
}