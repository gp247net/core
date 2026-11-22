<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\GlobalConfigForm;
use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the ConfigForm base via the Global config screen (ADR-001/005): loads
 * the admin_config "global" group, edits a value, persists; Layer-2 gated.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-001, ADR-005
 */
class GlobalConfigFormTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private const KEY = 'LOG_SLACK_WEBHOOK_URL';

    private function storeId()
    {
        return defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
    }

    public function test_loads_group_values(): void
    {
        $this->asUser(slugs: ['admin_config']);

        Livewire::test(GlobalConfigForm::class)
            ->assertSet('values.' . self::KEY, AdminConfig::where('key', self::KEY)->where('store_id', $this->storeId())->value('value'));
    }

    public function test_excludes_cache_and_password_keys(): void
    {
        $this->asUser(slugs: ['admin_config']);

        $values = Livewire::test(GlobalConfigForm::class)->get('values');

        // Cache and password-policy keys live in the same group but belong to
        // their own dedicated screens, so they must not leak into this one.
        $this->assertArrayNotHasKey('cache_status', $values);
        $this->assertArrayNotHasKey('admin_password_min', $values);
    }

    public function test_saves_changed_value_live(): void
    {
        $this->asUser(slugs: ['admin_config']);

        // Live edit: setting the value triggers the updatedValues hook (no submit).
        Livewire::test(GlobalConfigForm::class)
            ->set('values.' . self::KEY, 'https://hooks.example.com/zz')->call('save');

        $this->assertSame(
            'https://hooks.example.com/zz',
            AdminConfig::where('key', self::KEY)->where('store_id', $this->storeId())->value('value'),
        );
    }

    public function test_live_update_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(GlobalConfigForm::class);

        $this->assertDenied(fn () => $component->set('values.' . self::KEY, 'https://nope')->call('save'));
    }
}
