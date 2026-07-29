![GP247](https://static.gp247.net/logo/logo.png)

Core Laravel admin for all systems (ecommerce, cms, pmo...)

`composer require gp247/core`

[Installation and documentation](https://gp247.net) | [Facebook Official](https://www.facebook.com/GP247.official/)

[![Total Downloads](https://poser.pugx.org/gp247/core/d/total.svg)](https://packagist.org/packages/gp247/core)
[![Latest Stable Version](https://poser.pugx.org/gp247/core/v/stable.svg)](https://packagist.org/packages/gp247/core)
[![License](https://poser.pugx.org/gp247/core/license.svg)](https://packagist.org/packages/gp247/core)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/gp247net/core)

## About GP247
GP247 is a compact source code built with Laravel, helping users quickly build a powerful admin website. Whether your system is simple or complex, GP247 will help you operate and scale it easily.

**What can GP247 do?**

- Provides a powerful and flexible role management and user group solution.
- Offers a synchronous authentication API, enhancing API security with additional layers.
- Build and manage Plugins/Templates that work in the system
- Comprehensive access log monitoring system.
- Continuously updates security vulnerabilities.
- Supports multiple languages, easy management.
- GP247 is FREE

**And more:**

- GP247 builds a large, open ecosystem (plugin, template), helping users quickly build CMS, PMO, eCommerce, etc., according to your needs.

![GP247 screenshot](https://static.gp247.net/page/sc-3.jpg)

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
  >`php artisan gp247:core-install`

- **Step 6**: Add error handling

  To add custom error handling to your application, open the `bootstrap/app.php` file and add the following code to the `withExceptions` function:

  ```php
  ->withExceptions(function (Exceptions $exceptions) {
      $exceptions->report(function (\Throwable $e) {
          if (function_exists('gp247_handle_exception')) {
              gp247_handle_exception($e);
          }
      });
  });
  ```

  This code will help you handle exceptions through the `gp247_handle_exception` function if it exists.

## Useful information:

**To view GP247 version**

>`php artisan gp247:core-info`

**Update gp247**

Update the package using the command: 
>`composer update gp247/core`

Then, run the command: 

>`php artisan gp247:core-update`

**Optional — refresh published assets/views to the latest version.**
`composer update` only refreshes the package under `vendor/`; the files already
published to `public/GP247` and `resources/views/vendor/gp247-admin` are **not**
overwritten automatically. If a new release ships updated compiled CSS/JS or
admin views and you want them live, re-publish with `--force`:

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

