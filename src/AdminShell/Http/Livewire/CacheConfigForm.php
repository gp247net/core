<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\ConfigForm;

/**
 * Cache settings screen (admin_config "global" group, code "cache") — the modern
 * Livewire port of the legacy Cache config screen (AdminCacheConfigController):
 * cache on/off per entity type plus the cache lifetime. Gated by `admin_config`
 * (ADR-001/005).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-001, ADR-005
 */
class CacheConfigForm extends ConfigForm
{
    protected ?string $permission = 'admin_config';

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
            'cache_product',
            'cache_news',
            'cache_category_cms',
            'cache_content_cms',
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
            'cache_product' => 'bool',
            'cache_news' => 'bool',
            'cache_category_cms' => 'bool',
            'cache_content_cms' => 'bool',
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
}
