<?php
/**
 * Plugin helper functions (loaded by Provider.php when the plugin is active).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Update-safe user configuration (plugin standard #7 — ADR
 * plugin-manager_extension-update-flow, RISK-OPS-plugin-config-file-overwrite).
 *
 * 1-click update OVERWRITES every file of the plugin but PRESERVES admin_config
 * (DB). So config.php must hold DEFAULTS only; anything a site owner edits must
 * live in admin_config and be overlaid on the defaults at runtime — otherwise
 * the site owner's settings are reset to the new package's defaults on update.
 *
 * The AppConfig::uninstall() of this scaffold already cleans the admin_config
 * row whose `code` is `<configKey>_config`, so that is the conventional slot for
 * this override blob. Uncomment and adapt the helpers below when your plugin has
 * site-owner-editable settings; delete this block if it has none.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// if (!function_exists('Extension_Key_effective_config')) {
//     /**
//      * Effective settings = config.php defaults ⊕ admin_config overrides.
//      *
//      * @return array<string, mixed>
//      */
//     function Extension_Key_effective_config()
//     {
//         $defaults = (array) config('Plugins/Extension_Key.settings', []);
//
//         $row = \GP247\Core\Models\AdminConfig::where('group', 'Plugins')
//             ->where('key', 'Extension_Key_config')
//             ->first();
//         $overrides = $row ? json_decode((string) $row->value, true) : null;
//
//         return is_array($overrides) ? array_merge($defaults, $overrides) : $defaults;
//     }
// }
//
// if (!function_exists('Extension_Key_save_config')) {
//     /**
//      * Persist site-owner settings to admin_config (update-safe store).
//      *
//      * @param array<string, mixed> $settings
//      * @return void
//      */
//     function Extension_Key_save_config(array $settings)
//     {
//         \GP247\Core\Models\AdminConfig::updateOrCreate(
//             ['group' => 'Plugins', 'key' => 'Extension_Key_config'],
//             [
//                 'code' => 'Extension_Key_config',
//                 'store_id' => GP247_STORE_ID_GLOBAL,
//                 'value' => json_encode($settings),
//             ]
//         );
//     }
// }
