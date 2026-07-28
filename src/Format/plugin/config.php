<?php
/**
 * Plugin DEFAULT configuration.
 *
 * IMPORTANT (plugin standard #7 — ADR plugin-manager_extension-update-flow):
 * this file is package-owned and is OVERWRITTEN on 1-click update. Put only
 * immutable DEFAULTS and developer-level settings here. Any value a SITE OWNER
 * is expected to change (toggles, tunables) must be stored in `admin_config`
 * (DB) — which the update flow preserves — and overlaid on these defaults at
 * runtime. See function.php for the effective-config / save helpers, and
 * readme.md ("Update-safe user configuration") for the rationale.
 */
return [
    // 'key' => 'value'
    //
    // Example of a user-editable settings block (DEFAULTS only). The site
    // owner's chosen values live in admin_config, not here:
    // 'settings' => [
    //     'enabled' => 0,
    //     'items_per_page' => 20,
    // ],
];
