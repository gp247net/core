<?php

namespace GP247\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Ecosystem fingerprint for GP247-powered sites.
 *
 * Every site in the GP247 ecosystem is required to run gp247/core, so this
 * global middleware is the single, template-independent place that lets
 * technology-detection services (Wappalyzer, BuiltWith, W3Techs, ...) recognise
 * a GP247 site — regardless of whether a public storefront (gp247/front) is
 * installed or which storefront template is active.
 *
 * It emits two safe, versionless markers:
 *   1. An HTTP response header on every response.
 *   2. A `<meta name="generator">` tag injected into full HTML documents.
 *
 * SECURITY: the marker is a constant brand string only. It intentionally never
 * exposes the package/PHP/Laravel version, so it cannot be used to target
 * version-specific vulnerabilities across the ecosystem (see .claude/rules/security.md).
 * The whole behaviour is opt-out via `config('gp247.fingerprint')`
 * (env GP247_FINGERPRINT=false) for white-label / privacy-conscious owners.
 *
 * @aidlc-unit ecosystem-fingerprint
 * @aidlc-story US-BRAND-fingerprint
 */
class Fingerprint
{
    /**
     * Brand marker — versionless on purpose (see class-level SECURITY note).
     */
    private const MARKER = 'GP247';

    /**
     * Custom response header carrying the marker. Prefixed with `X-` and the
     * brand so it never collides with a standard or third-party header.
     */
    private const HEADER_NAME = 'X-Powered-By-GP247';

    /**
     * Meta tag injected into the <head> of HTML documents.
     */
    private const META_TAG = '<meta name="generator" content="' . self::MARKER . '">';

    /**
     * Handle an incoming request and decorate the outgoing response with the
     * GP247 fingerprint markers.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Opt-out switch: when disabled, the response passes through untouched.
        if (!config('gp247.fingerprint', true)) {
            return $response;
        }

        // Header applies to every response kind (HTML, JSON, redirect, ...).
        $response->headers->set(self::HEADER_NAME, self::MARKER);

        $this->injectGeneratorMeta($response);

        return $response;
    }

    /**
     * Inject the `<meta name="generator">` tag right after the opening <head>
     * tag of a full HTML document.
     *
     * WHY response-level injection (not a Blade partial): the fingerprint must
     * survive both "core-only" sites (no storefront view at all) and custom
     * storefront templates that override the front package's <head> component.
     * Rewriting the response body is the only layer every GP247 site shares.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return void
     */
    protected function injectGeneratorMeta($response): void
    {
        // Only full HTML documents rendered through a standard buffered Response.
        // JsonResponse, redirects and streamed/binary responses never match this
        // type, so their bodies are left intact.
        if (!$response instanceof Response) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type');
        if (stripos($contentType, 'text/html') === false) {
            return;
        }

        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return;
        }

        // Respect a document that already declares a generator (a site override
        // or another tool set it) — never emit a duplicate meta tag.
        if (stripos($content, 'name="generator"') !== false) {
            return;
        }

        // Insert immediately after the first opening <head ...> tag only.
        $updated = preg_replace(
            '/<head\b[^>]*>/i',
            '$0' . "\n    " . self::META_TAG,
            $content,
            1,
            $count
        );

        if ($updated !== null && $count > 0) {
            $response->setContent($updated);
        }
    }
}
