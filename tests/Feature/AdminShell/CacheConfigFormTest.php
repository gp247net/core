<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\CacheConfigForm;
use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the dedicated Cache config screen (admin_config "global" group, cache
 * keys; ADR-001/005): loads only the cache_* keys, persists a value live and is
 * Layer-2 gated — restored as its own screen, not lumped into Global config.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-001, ADR-005
 */
class CacheConfigFormTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private function storeId()
    {
        return defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
    }

    public function test_loads_only_cache_keys(): void
    {
        $this->asUser(slugs: ['admin_config']);

        $values = Livewire::test(CacheConfigForm::class)->get('values');

        $this->assertArrayHasKey('cache_status', $values);
        $this->assertArrayHasKey('cache_time', $values);
        // Webhook / password keys share the group but are not this screen's concern.
        $this->assertArrayNotHasKey('LOG_SLACK_WEBHOOK_URL', $values);
        $this->assertArrayNotHasKey('admin_password_min', $values);
    }

    public function test_saves_cache_time_live(): void
    {
        $this->asUser(slugs: ['admin_config']);

        Livewire::test(CacheConfigForm::class)->set('values.cache_time', '900')->call('save');

        $this->assertSame(
            '900',
            AdminConfig::where('key', 'cache_time')->where('store_id', $this->storeId())->value('value'),
        );
    }

    public function test_status_toggle_persists_one_zero(): void
    {
        $this->asUser(slugs: ['admin_config']);

        // The status field is rendered as an on/off switch but still binds a bool.
        Livewire::test(CacheConfigForm::class)->set('values.cache_status', true)->call('save');

        $this->assertSame(
            '1',
            AdminConfig::where('key', 'cache_status')->where('store_id', $this->storeId())->value('value'),
        );

        Livewire::test(CacheConfigForm::class)->set('values.cache_status', false)->call('save');

        $this->assertSame(
            '0',
            AdminConfig::where('key', 'cache_status')->where('store_id', $this->storeId())->value('value'),
        );
    }

    public function test_live_update_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(CacheConfigForm::class);

        $this->assertDenied(fn () => $component->set('values.cache_time', '5')->call('save'));
    }

    /**
     * The dead cache flags (no reader anywhere) were pruned from the screen so the
     * admin can no longer toggle a switch that does nothing.
     *
     * @aidlc-story US-AUI-cache-config-hardening
     */
    public function test_dead_cache_flags_are_not_loaded(): void
    {
        $this->asUser(slugs: ['admin_config']);

        $values = Livewire::test(CacheConfigForm::class)->get('values');

        foreach (['cache_product', 'cache_news', 'cache_category_cms', 'cache_content_cms'] as $dead) {
            $this->assertArrayNotHasKey($dead, $values);
        }
        // Surviving flags remain.
        foreach (['cache_status', 'cache_time', 'cache_category', 'cache_page', 'cache_country'] as $live) {
            $this->assertArrayHasKey($live, $values);
        }
    }

    /**
     * cache_time is the default cache TTL (seconds); 0/negative/non-numeric would
     * mean "cache forever" on some drivers, so each is clamped to the 600s fallback.
     *
     * @aidlc-story US-AUI-cache-config-hardening
     */
    public function test_cache_time_rejects_zero_or_negative(): void
    {
        $this->asUser(slugs: ['admin_config']);

        foreach (['0', '-5', 'abc'] as $invalid) {
            Livewire::test(CacheConfigForm::class)->set('values.cache_time', $invalid)->call('save');

            $this->assertSame(
                '600',
                AdminConfig::where('key', 'cache_time')->where('store_id', $this->storeId())->value('value'),
                "cache_time input [{$invalid}] should be clamped to 600",
            );
        }
    }

    public function test_cache_time_accepts_valid_positive(): void
    {
        $this->asUser(slugs: ['admin_config']);

        Livewire::test(CacheConfigForm::class)->set('values.cache_time', '1200')->call('save');

        $this->assertSame(
            '1200',
            AdminConfig::where('key', 'cache_time')->where('store_id', $this->storeId())->value('value'),
        );
    }
}
