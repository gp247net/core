```
  _____  _____     ___  _  _   _____ 
 / ____|  __ \   |__ \| || | |___  |
| |  __| |__) |     ) | || |_   / / 
| | |_ |  ___/     / /|__   _| / /  
| |__| | |        / /_   | |  / /   
 \_____|_|       |____|  |_| /_/    
```

> 🌐 **Language:** 🇬🇧 English (current) · [🇻🇳 Tiếng Việt](readme_vi.md)

Install the core (Laravel already set up):

> composer require gp247/core

[Home page](https://gp247.net) | [Official documentation](https://github.com/gp247net/gp247-docs) | [Official agent skills](https://github.com/gp247net/gp247-skills) | [Official fanpage](https://www.facebook.com/GP247.official/)

[![Total Downloads](https://poser.pugx.org/gp247/core/d/total.svg)](https://packagist.org/packages/gp247/core)
[![Latest Stable Version](https://poser.pugx.org/gp247/core/v/stable.svg)](https://packagist.org/packages/gp247/core)
[![License](https://poser.pugx.org/gp247/core/license.svg)](https://packagist.org/packages/gp247/core)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/gp247net/core)

## About GP247
GP247 is a powerful, secure, flexible, and AI-agent-friendly open-source system built on the Laravel framework.

Main components in the GP247 ecosystem:

- **[Package] GP247/core**: The core of the entire ecosystem. GP247/core can operate independently as a Laravel admin.
- **[Package] GP247/front**: A package that provides the basic features for a business CMS.
- **[Package] GP247/shop**: Provides the full features of an e-commerce website.

 `[Project] S-Cart = GP247/core + GP247/front + GP247/shop`


## Laravel core:

GP247 2.x

> Core laravel framework 13.x 


## Website structure using GP247

    Website-folder/
    |
    ├── app
    │     └── GP247
    │           ├── Core(+) //Customize controller of Core
    │           ├── Helpers(+) //Auto load Helpers/*.php
    │           ├── Plugins(+) //Use `php artisan gp247:make-plugin --name=NameOfPlugin`
      //(IF you have gp247/front installed)//
    │           ├── Front(+) //Customize controller of Front 
      //(IF you have gp247/shop installed)//
    │           ├── Shop(+) //Customize controller of Shop 
    │           └── Templates(+) /Use `php artisan gp247:make-template --name=NameOfTempate`
    ├── public
    │     └── GP247
    │           ├── Core(+)
    │           ├── Plugins(+)
      //(IF you have gp247/front installed)//
    │           └── Templates(+)
    ├── resources
    │            └── views/vendor
    │                           |── gp247-admin(+) //Customize view core
    │                           |── gp247-front-admin(+) //(IF you have gp247/front installed)//
    │                           └── gp247-shop-admin(+) //(IF you have gp247/shop installed)//
    ├── vendor
    │     ├── gp247/core
    │     ├── gp247/front
    │     └── gp247/shop
    ├── .env
    │     └── GP247_ACTIVE=1 //ON|OFF gp247
    └──...


## Quick Installation Guide
- **Step 1**: Prepare the Laravel source

  Refer to the command: 
  >`composer create-project laravel/laravel website-folder`

- **Step 2**: Install the gp247/core package

  Move to Laravel directory (in this example is `website-folder`), and run the command:

  >`composer require gp247/core`

- **Step 3**: Check the configuration in the .env file

  Ensure that the database configuration and APP_KEY information in the .env file are complete.

  If the APP_KEY is not set, use the following command to generate it: 
  >`php artisan key:generate`

- **Step 4**: Configure database
  
Default, GP247 uses mysql. The configuration will be saved in the .env file as follows:
```
  DB_CONNECTION=mysql
  DB_HOST=127.0.0.1
  DB_PORT=3306
  DB_DATABASE=gp247
  DB_USERNAME=root
  DB_PASSWORD=
```

  If you want to use sqlite for quick testing, please change the connection in the .env file to sqlite, and comment out the DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD lines.
  
```
    DB_CONNECTION=sqlite
    #DB_HOST=127.0.0.1
    #DB_PORT=3306
    #DB_DATABASE=gp247
    #DB_USERNAME=root
    #DB_PASSWORD=
```
- **Step 5**: Initialize gp247

  Run the command: 
  >`php artisan gp247:install`

  `gp247:install` auto-detects the GP247 packages present and installs them in
  order (core [+front] [+shop]); it asks to confirm by default, add `--force=1`
  for an unattended install.

- **Step 6**: Add error handling

  To add custom error handling to your application, open the `bootstrap/app.php` file and add the following code to the `withExceptions` function:

  ```php
        // GP247 add new
        $exceptions->report(function (\Throwable $e) {
            try {
                if (function_exists('gp247_handle_exception')) {
                    gp247_handle_exception($e);
                }
            } catch (\Throwable $inner) {
                \Log::error($e->getMessage(), ['exception' => $e]);
            }
        });
  ```

  This code will help you handle exceptions through the `gp247_handle_exception` function if it exists.

## Useful information:

> 📖 **Full command-line reference.** The commands below are the most common ones.
> For every GP247 artisan command, its options and examples, see the official
> reference: [English](https://github.com/gp247net/gp247-docs/blob/main/system/command-line-reference.md)
> · [Tiếng Việt](https://github.com/gp247net/gp247-docs/blob/main/system/command-line-reference_vi.md).

**To view GP247 version**

>`php artisan gp247:info`

**Update gp247**

Update the package using the command: 
>`composer update gp247/core`

Then run the safe, non-destructive refresh (updates core, updates the shop
schema when the shop module is installed, and rebuilds the caches): 

>`php artisan gp247:update`

**Optional — refresh language files.** By default `gp247:update` leaves your
translations untouched. Add `--overwrite-lang` to also pull the latest
translations, **overwriting any language strings you edited**:

>`php artisan gp247:update --overwrite-lang`

**Optional — refresh published assets/views to the latest version.**
`composer update` only refreshes the package under `vendor/`; the files already
published to `public/GP247` and `resources/views/vendor/gp247-admin` are **not**
overwritten automatically. The easiest way is the **opt-in** `--publish=<tokens>`
option of `gp247:update` (default publishes nothing). Only `core-public` is safe
(compiled admin assets); the view tokens overwrite your customizations, so back
up first:

>`php artisan gp247:update --publish=core-public` // safe: refresh admin CSS/JS

>`php artisan gp247:update --publish=core-public,core-view` // also overwrite admin views (DESTRUCTIVE)

There is no `--force` flag on `gp247:update` — typing a destructive token is the
consent, and an interactive run still warns and asks to confirm.

Alternatively, publish each tag manually with `vendor:publish --force`:

>`php artisan vendor:publish --tag=gp247:core-public --force` // -> public/GP247 (admin build: CSS/JS, ...)

>`php artisan vendor:publish --tag=gp247:core-view --force` // -> resources/views/vendor/gp247-admin

`IF you have gp247/front installed`, also re-publish the front assets/views:

>`php artisan vendor:publish --tag=gp247:front-public --force` // -> public/GP247/Templates/GP247Front (storefront CSS/JS)

>`php artisan vendor:publish --tag=gp247:front-view --force` // -> app/GP247/Templates/GP247Front (default template views)

> ⚠️ `--force` **overwrites** the destination files, including any local
> customizations made there. **Back up `public/GP247` and the published view
> folder first**, and publish only the tag you actually need.

**To create a plugin:**

>`php artisan gp247:make-plugin  --name=PluginName`

To create a zip file plugin

>`php artisan gp247:make-plugin  --name=PluginName --download=1`

**To create a template (`IF you have gp247/front installed`):**

>`php artisan gp247:make-template  --name=TemplateName`

To create a zip file template:

>`php artisan gp247:make-template  --name=TemplateName --download=1`

## Customize


**Customize lfm configuration for upload**

>`php artisan vendor:publish --tag=config-lfm`

**Customize core admin view**

>`php artisan vendor:publish --tag=gp247:core-view` // -> views/vendor/gp247-admin

**Overwrite gp247_* helper functions**

>Step 1: Add the list of functions you want to override to `config/gp247_functions_except.php`

>Step 2: Create new php files containing the new functions in the `app/GP247/Helpers` directory, for example `app/GP247/Helpers/myfunction.php`

**Overwrite gp247 controller files**

>Step 1: Copy the controller files you want to override from vendor/gp247/core/src/Core/Controllers -> app/GP247/Core/Controllers

>Step 2: Change `namespace GP247\Core\Controllers` to `namespace App\GP247\Core\Controllers`

**Overwrite gp247 API controller files**

>Step 1: Copy the controller files you want to override from vendor/gp247/core/src/Api/Controllers -> app/GP247/Core/Api/Controllers

>Step 2: Change `namespace GP247\Core\Api\Controllers` to `namespace App\GP247\Core\Api\Controllers`

## Add route

Use prefix and middleware constants `GP247_ADMIN_PREFIX`, `GP247_ADMIN_MIDDLEWARE` in route declaration.

References: https://github.com/gp247net/core/blob/master/src/routes.php



## Environment variables in .env file

**Quickly disable GP247 and plugins**
> `GP247_ACTIVE=1` // To disable, set value 0

**Disable APIs**
> `GP247_API_MODE=1` // To disable, set value 0

**Data table prefixes**
> `GP247_DB_PREFIX=gp247_` //Cannot change after install gp247

**Path prefix to admin**
> `GP247_ADMIN_PREFIX=gp247_admin`

