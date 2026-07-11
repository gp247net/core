<?php

namespace App\GP247\Plugins\Extension_Key;

/**
 * Sitemap URL provider for this plugin (US-PLG-007, ADR
 * seo_plugin-sitemap-extension in the s-cart `aidlc-docs` repo). Registered
 * by `Provider.php` into `config('gp247-config.front.seo_sitemap_providers')`
 * so `gp247/front`'s `SeoController` includes this plugin's public pages in
 * `sitemap.xml` without `gp247/front` hardcoding this plugin's name.
 *
 * Fill in {@see sitemapUrls()} once this plugin has a model with its own
 * public detail page (route + alias) — see the commented example below. Until
 * then this returns an empty array, which is safe: the plugin still shows up
 * as a toggle on the admin "SEO" screen, it just has nothing to contribute yet.
 */
class Seo
{
    /**
     * Active/public rows for the given store, shaped for
     * `SeoController::collectUrls()`. `alias` is required so the admin's
     * `seo.sitemap_exclude_aliases` wildcard filter applies to this plugin's
     * URLs the same way it applies to pages/products/categories.
     *
     * @param  mixed $storeId
     * @return array<int, array{alias:string, loc:string, lastmod?:string, changefreq?:string, priority?:string}>
     */
    public static function sitemapUrls($storeId): array
    {
        // Example — adapt to this plugin's own model/route:
        //
        // return \App\GP247\Plugins\Extension_Key\Models\ExtensionModel::where('store_id', $storeId)
        //     ->where('status', 1)
        //     ->get(['alias', 'updated_at'])
        //     ->map(fn ($row) => [
        //         'alias'      => $row->alias,
        //         'loc'        => gp247_route_front('Extension_Key.detail', ['alias' => $row->alias]),
        //         'lastmod'    => $row->updated_at?->format('Y-m-d'),
        //         'changefreq' => 'weekly',
        //         'priority'   => '0.6',
        //     ])
        //     ->all();

        return [];
    }
}
