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

> Core laravel framework 11.x 


## Website structure using GP247

    Website-folder/
    |
    ├── app
    │     └── GP247
    │           ├── Core(+) //Customize controller of Core
    │           ├── Helpers(+) //Auto load Helpers/*.php
    │           ├── Blocks(+) // The list of blades will be used to display on the admin homepage.
    │           ├── Plugins(+) //Use `php artisan gp247:make-plugin --name=NameOfPlugin`
      //(IF you have gp247/front installed)//
    │           ├── Front(+) //Customize controller of Front 
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

## Support the project
Support this project :stuck_out_tongue_winking_eye: :pray:
<p align="center">
    <a href="https://www.paypal.me/LeLanh" target="_blank"><img src="https://img.shields.io/badge/Donate-PayPal-green.svg" data-origin="https://img.shields.io/badge/Donate-PayPal-green.svg" alt="PayPal Me"></a>
</p>

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

- **Step 4**: Initialize gp247

  Run the command: 
  >`php artisan gp247:install`


## Useful information:

**To view GP247 version**

>`php artisan gp247:info`

**Update gp247**

Update the package using the command: 
>`composer update gp247/core`

Then, run the command: 

>`php artisan gp247:update`

**To create a plugin:**

>`php artisan gp247:make-plugin  --name=PluginName`

To create a zip file plugin

>`php artisan gp247:make-plugin  --name=PluginName --download=1`

**To create a template (`IF you have gp247/front installed`):**

>`php artisan gp247:make-template  --name=TemplateName`

To create a zip file template:

>`php artisan gp247:make-template  --name=TemplateName --download=1`

## Customize

**Customize gp247-config and functions**

>`php artisan gp247:customize config`

**Customize view admin**

>`php artisan gp247:customize view`

**Overwrite gp247_* helper functions**

>Step 1: Use the command `php artisan gp247:customize config` to copy the file `app/config/gp247_functions_except.php`

>Step 2: Add the list of functions you want to override to `gp247_functions_except.php`

>Step 3: Create a new function in the `app/GP247/Helpers folder`

**Overwrite gp247 controller files**

>Step 1: Copy the controller files you want to override in vendor/gp247/core/src/Core/Controllers -> app/GP247/Core/Admin/Controllers

>Step 2: Change `namespace GP247\Core\Admin\Controllers` to `namespace App\GP247\Core\Admin\Controllers`

**Overwrite gp247 API controller files**

>Step 1: Copy the controller files you want to override in vendor vendor/gp247/core/src/Api/Controllers ->  app/GP247/Core/Api/Controllers

>Step 2: Change `namespace GP247\Core\Api\Controllers` to `namespace App\GP247\Core\Api\Controllers`

## Add route

Use prefix and middleware constants `GP247_ADMIN_PREFIX`, `GP247_ADMIN_MIDDLEWARE` in route declaration.

References: https://github.com/gp247net/core/blob/master/src/Admin/routes.php



## Environment variables in .env file

**Quickly disable GP247 and plugins**
> `GP247_ACTIVE=1` // To disable, set value 0

**Disable APIs**
> `GP247_API_MODE=1` // To disable, set value 0

**Data table prefixes**
> `GP247_DB_PREFIX=gp247_` //Cannot change after install gp247

**Path prefix to admin**
> `GP247_ADMIN_PREFIX=gp247_admin`

