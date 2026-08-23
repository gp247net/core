<?php
/**
 * Provides everything needed for the Extension
 */

 $config = file_get_contents(__DIR__.'/gp247.json');
 $config = json_decode($config, true);
 $extensionPath = $config['configGroup'].'/'.$config['configKey'];
 
 $this->loadTranslationsFrom(__DIR__.'/Lang', $extensionPath);
 
 if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {

     $this->loadViewsFrom(__DIR__.'/Views', $extensionPath);

     if (file_exists(__DIR__.'/config.php')) {
         $this->mergeConfigFrom(__DIR__.'/config.php', $extensionPath);
     }

     if (file_exists(__DIR__.'/function.php')) {
         require_once __DIR__.'/function.php';
     }

     // US-PLG-004: register the plugin's Livewire class namespace so its admin
     // component resolves as <livewire:Extension_Key::admin-livewire> on the
     // TailAdmin shell (and via the full-page route in Route.php), without
     // relying on Composer autoload discovery on the host. Guarded so a host
     // without Livewire installed still boots the plugin cleanly.
     if (class_exists(\Livewire\Livewire::class)) {
         \Livewire\Livewire::addNamespace('Extension_Key', classNamespace: 'App\\GP247\\Plugins\\Extension_Key\\Livewire');
     }

     // US-PLG-007: register this plugin into gp247/front's sitemap.xml (ADR
     // seo_plugin-sitemap-extension). Safe to leave as-is even before
     // Seo::sitemapUrls() is filled in — the plugin still shows up as a
     // toggle on the admin "SEO" screen, it just has nothing to contribute
     // yet. Guarded so plugins that don't require gp247/front still install
     // cleanly (same guard style as the front routes in Route.php).
     if (class_exists('GP247\Front\Controllers\RootFrontController')) {
         $sitemapProviders = config('gp247-config.front.seo_sitemap_providers', []);
         $sitemapProviders[] = [
             'key' => $config['configKey'],
             'label' => $config['name'],
             'callback' => [\App\GP247\Plugins\Extension_Key\Seo::class, 'sitemapUrls'],
         ];
         config(['gp247-config.front.seo_sitemap_providers' => $sitemapProviders]);
     }

     // US-PLG-008 (optional — only for plugins that render their OWN storefront
     // page): register the plugin's page-type token(s) into gp247/front's
     // LayoutBlock "Page" scope registry so an admin can target a LayoutBlock to
     // those pages (ADR front-admin_layout-page-enum-catalog). Store the i18n
     // KEY (not a pre-rendered string) so the admin dropdown renders it in the
     // viewer's locale. The token must equal the $layout_page value your
     // controller emits to view() for that page. Uncomment + fill in for
     // producer plugins; leave removed for admin-only plugins. See the News
     // plugin (app/GP247/Plugins/News/Provider.php) as a reference.
     // if (class_exists('GP247\Front\Controllers\RootFrontController')) {
     //     $layoutPage = config('gp247-config.front.layout_page', []);
     //     $layoutPage['myplugin_index'] = $extensionPath.'::lang.layout_block_page.myplugin_index';
     //     config(['gp247-config.front.layout_page' => $layoutPage]);
     // }
 }