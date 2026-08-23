# Create New Plugin

To create a new plugin, use the following artisan command:

```bash
php artisan gp247:make-plugin --name=YourPluginName --download=0
```

Where:
- `YourPluginName`: Your plugin name
- `--download=0`: Create plugin directly in app/GP247/Plugins directory
- `--download=1`: Create plugin zip file in storage/tmp directory


# GP247 Plugin Structure

This is the standard template for developing plugins in the GP247 system. The plugin is designed following the MVC (Model-View-Controller) pattern and adheres to Laravel framework rules.

## Directory Structure

```
plugin/
├── Admin/           # Contains admin-related files
├── Controllers/     # Contains logic handling controllers
├── Lang/           # Contains language files
├── Models/         # Contains models
├── public/         # Contains public files (css, js, images). When installed, will be copied to public/GP247/Plugins/Your-plugin
├── Views/          # Contains view files
├── AppConfig.php   # Main plugin configuration file
├── config.php      # Configuration file
├── function.php    # Contains helper functions
├── gp247.json      # Plugin information declaration file
├── Provider.php    # Plugin service provider
├── Route.php       # Route definitions
└── route_front.stub # Frontend route template
```

## Key Files

### 1. gp247.json
File declaring basic plugin information:
- name: Plugin name
- image: Plugin logo
- auth: Author
- configGroup: Configuration group
- configCode: Configuration code
- configKey: Configuration key, must be unique and match the plugin folder name
- version: Version
- requireCore: Compatible Gp247/Core version (use ["2.1"] for the current standard)
- requireUpdateFrom: Minimum currently-installed version allowed to 1-click update to this release. Defaults to the scaffold version (no restriction in practice); raise it when shipping a major release whose update() hook cannot migrate from older lines — e.g. set "2.0" on a 2.9 release to block updating from a 1.x install. Omit for no floor.
- requireComposerPackages: Required Composer packages from packagist.org (e.g. gp247/front). Renamed from `requirePackages` in gp247/core 2.1 (old key still read by core but deprecated).
- requireGp247Extensions: Required GP247 extensions (installed plugins, templates). Example: Shop, Front, News,... Renamed from `requireExtensions` in gp247/core 2.1 (old key still read by core but deprecated).

### 2. AppConfig.php
Main plugin configuration file, contains methods:
- install(): Install plugin
- uninstall(): Uninstall plugin
- enable(): Enable plugin
- disable(): Disable plugin
- setupStore(): Setup for store
- removeStore(): Remove store setup
- clickApp(): Handle when clicking plugin in admin
- getInfo(): Get plugin information

### 3. Provider.php
Plugin service provider, registers services and middleware.

### 4. Route.php
Defines plugin routes.

### 5. config.php & function.php — Update-safe user configuration (standard #7)
1-click plugin update **overwrites every file** of the plugin but **preserves `admin_config` (DB)** — see ADR `plugin-manager_extension-update-flow`, RISK-OPS-plugin-config-file-overwrite. Therefore:
- **`config.php` = DEFAULTS only** (package-owned, overwritten on update). Put immutable defaults and developer-level settings here.
- **Any value a SITE OWNER edits** (toggles, tunables) must be stored in `admin_config` and overlaid on the defaults at runtime — otherwise those choices are reset to the new package's defaults on every update.
- The scaffold's `AppConfig::uninstall()` already cleans the `admin_config` row whose `code` is `<configKey>_config`; that is the conventional slot for the override blob. `function.php` ships commented `*_effective_config()` / `*_save_config()` helpers implementing the `default(file) ⊕ override(DB)` overlay — uncomment and adapt them when your plugin has site-owner-editable settings, or delete them if it has none.
- Reference implementation: the `MFA` plugin (guard `enabled`/`forced` + tunables live in `admin_config`; `config.php` keeps only the model/redirect defaults).

## Usage

1. Create new plugin:
   - Rename directory to match template name (must match configKey value)
   - Update information in gp247.json

2. Development:
   - Add logic to Controllers
   - Create models in Models
   - Create views in Views
   - Add languages in Lang
   - Add assets in public

3. Installation:
   - Please refer to detailed installation guide at: https://gp247.net/en/user-guide-extension/guide-to-installing-the-extension.html


## Notes

- Follow MVC structure
- Use correct namespace
- Ensure multilingual support
- Check dependencies before installation
- Handle errors and rollback when necessary
