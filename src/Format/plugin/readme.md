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
- requireCore: Compatible Gp247/Core version
- requirePackages: Required packages from packagist.org
- requireExtensions: Required GP247 extensions (plugins, templates). Example: Shop, Front, News,...

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
