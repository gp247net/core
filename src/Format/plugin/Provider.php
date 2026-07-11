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

     // US-PLG-004: register the plugin's Livewire class namespace so its admin
     // screens resolve under "<configGroup>/<configKey>::*". Guarded by
     // class_exists so plugins still load on installs without Livewire, and the
     // directory check keeps legacy (controller-only) plugins unaffected.
     if (class_exists(\Livewire\Livewire::class) && is_dir(__DIR__.'/Livewire')) {
         \Livewire\Livewire::addNamespace(
             $extensionPath,
             classNamespace: 'App\\GP247\\Plugins\\Extension_Key\\Livewire',
         );
     }

     if (file_exists(__DIR__.'/config.php')) {
         $this->mergeConfigFrom(__DIR__.'/config.php', $extensionPath);
     }

     if (file_exists(__DIR__.'/function.php')) {
         require_once __DIR__.'/function.php';
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
 }