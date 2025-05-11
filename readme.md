<p align="center">
    <img src="https://static.gp247.net/logo/logo.png" width="150">
</p>
<p align="center">Core Laravel admin for all systems (ecommerce, cms, pmo...)<br>
    <code><b>composer require gp247/core</b></code></p>
<p align="center">
 <a href="https://gp247.net">Installation and documentation</a>
</p>

<p align="center">
<a href="https://packagist.org/packages/gp247/core"><img src="https://poser.pugx.org/gp247/core/d/total.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/gp247/core"><img src="https://poser.pugx.org/gp247/core/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/gp247/core"><img src="https://poser.pugx.org/gp247/core/license.svg" alt="License"></a>
</p>

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

<p align="center">
    <img src="https://static.gp247.net/page/gp247-screen.jpg" width="100%">
</p>

## Laravel core:

GP247 1.x

> Core laravel framework 12.x 


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
    │                           |── gp247-core(+) //Customize view core
    │                           └── gp247-front(+) //(IF you have gp247/front installed)//
    ├── vendor
    │     ├── gp247/core
    │     └── gp247/front
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

>`php artisan vendor:publish --tag=gp247:view-core`

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

