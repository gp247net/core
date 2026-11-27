<?php

namespace GP247\Core\SecurityHeaders\Http;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/**
 * Takes over the two source-map URLs of the Livewire script.
 *
 * Livewire serves `livewire.min.js.map` (and the CSP build's map) on every site, even with
 * debug off, so security scanners report "source map exposed" on every live store. Outside
 * debug mode these URLs answer 404; while debugging they still return Livewire's map so a
 * developer keeps readable stack traces in the browser.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-no-source-maps
 */
final class LivewireSourceMapController
{
    /**
     * Register this controller on Livewire's map URLs, replacing Livewire's own routes.
     *
     * Call it after every provider has booted: a route registered later for the same method
     * and URI replaces the earlier one in the route collection.
     *
     * @return void
     */
    public static function register(): void
    {
        // WHY: with `route:cache` the override is already inside the cached routes; adding a route
        // at runtime would turn the compiled collection back into a slow, uncompiled one.
        if (app()->routesAreCached() || !class_exists(EndpointResolver::class)) {
            return;
        }

        Route::get(EndpointResolver::mapPath(csp: false), [self::class, 'standard']);
        Route::get(EndpointResolver::mapPath(csp: true), [self::class, 'csp']);
    }

    /**
     * Source map of the standard Livewire script.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function standard()
    {
        return config('app.debug') ? app(FrontendAssets::class)->maps() : $this->notFound();
    }

    /**
     * Source map of the CSP-safe Livewire script.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function csp()
    {
        return config('app.debug') ? app(FrontendAssets::class)->cspMaps() : $this->notFound();
    }

    /**
     * Bare 404 for a map that is not served.
     *
     * WHY not abort(404): that renders the storefront's 404 page (database, template) for every
     * scanner and browser dev tool asking for the map; an empty answer costs nothing.
     *
     * @return Response
     */
    private function notFound(): Response
    {
        return new Response('', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
