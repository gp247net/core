<?php

namespace GP247\Core\Support\Health;

use Illuminate\Support\Facades\Route;

/**
 * Text, help-link and fix-link helpers shared by the health checks.
 *
 * The checks also run from `gp247:doctor`, a bootstrap-tier command that may run
 * before the gp247_* helpers exist, so every helper is called defensively.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 * @aidlc-adr admin-shell_system-alert-registry
 */
final class HealthText
{
    /** Public documentation site; pages live under /<locale>/docs/security/. */
    public const DOCS_BASE = 'https://gp247.net';

    private const DOC_LOCALES = ['vi', 'en', 'ja'];

    /**
     * Translate an admin language code (falls back to the code itself).
     *
     * @param string                $code
     * @param array<string, scalar> $replace
     * @return string
     */
    public static function t(string $code, array $replace = []): string
    {
        if (function_exists('gp247_language_render')) {
            return (string) gp247_language_render($code, $replace);
        }

        return $code;
    }

    /**
     * Security help page in the admin's language (vi/en/ja, else en).
     *
     * @param string $page Page slug, e.g. "security-production-config".
     * @return string
     */
    public static function doc(string $page): string
    {
        $locale = function_exists('gp247_get_locale') ? (string) gp247_get_locale() : (string) app()->getLocale();
        if (!in_array($locale, self::DOC_LOCALES, true)) {
            $locale = 'en';
        }

        return self::DOCS_BASE . '/' . $locale . '/docs/security/' . $page . '.html';
    }

    /**
     * URL of an admin route, or '' when the route is not registered.
     *
     * @param string $name
     * @return string
     */
    public static function route(string $name): string
    {
        if (!Route::has($name)) {
            return '';
        }

        try {
            return route($name);
        } catch (\Throwable $e) {
            // WHY: a route needing parameters cannot be a fix button — show none.
            return '';
        }
    }
}
