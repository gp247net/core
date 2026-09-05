<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\ConfigForm;

/**
 * Cache settings screen (admin_config "global" group, code "cache") — the modern
 * Livewire port of the legacy Cache config screen (AdminCacheConfigController):
 * cache on/off per entity type plus the cache lifetime. Gated by `admin_config`
 * (ADR-001/005).
 *
 * Only flags that a consumer actually reads via gp247_config_global() are exposed:
 * cache_status (master), cache_time (TTL), cache_category, cache_page, cache_country.
 * The legacy cache_product / cache_news / cache_category_cms / cache_content_cms
 * flags were dead config (no reader) and were removed (modification 20260812T142238).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-cache-config-hardening
 * @aidlc-adr admin-shell_cache-config-hardening
 */
class CacheConfigForm extends ConfigForm
{
    protected ?string $permission = 'admin_config';

    /**
     * Lower bound (seconds) for the cache TTL; also the fallback when the admin
     * enters an invalid value. Matches the gp247_cache_set() default of 600s.
     */
    private const CACHE_TIME_FALLBACK = 600;

    /**
     * @return string
     */
    protected function group(): string
    {
        return 'global';
    }

    /**
     * @return array<int, string>
     */
    protected function keys(): array
    {
        return [
            'cache_status',
            'cache_time',
            'cache_category',
            'cache_page',
            'cache_country',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldTypes(): array
    {
        return [
            'cache_status' => 'toggle',
            'cache_time' => 'number',
            'cache_category' => 'bool',
            'cache_page' => 'bool',
            'cache_country' => 'bool',
        ];
    }

    /**
     * @return string
     */
    protected function heading(): string
    {
        return gp247_language_render('admin.cache.title');
    }

    /**
     * Livewire hook: validate cache_time before the base class persists it.
     *
     * WHY: cache_time is the default cache TTL (seconds) used by gp247_cache_set().
     * A mistyped 0/negative/non-numeric value would otherwise be stored verbatim
     * and, on some drivers, Cache::put(..., 0) means "no expiry" (cache forever) —
     * the opposite of the admin's intent. Clamp anything below the lower bound back
     * to the 600s fallback on Save. Runs at the base persist choke (normalizeValue),
     * which the submit-based save() calls per key (ADR-005 amended 2026-09-05).
     *
     * @param string $key   The config key.
     * @param mixed  $value The submitted value.
     * @return mixed The normalised value.
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-AUI-cache-config-hardening
     */
    protected function normalizeValue(string $key, $value)
    {
        if ($key === 'cache_time') {
            $seconds = is_numeric($value) ? (int) $value : 0;
            if ($seconds < 1) {
                $seconds = self::CACHE_TIME_FALLBACK;
            }

            return (string) $seconds;
        }

        return $value;
    }
}
