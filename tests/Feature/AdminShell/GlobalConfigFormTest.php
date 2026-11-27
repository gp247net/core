<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\GlobalConfigForm;
use GP247\Core\Api\Sync\OutboundUrlGuard;
use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the ConfigForm base via the Global config screen (ADR-001/005): loads
 * the admin_config "global" group, edits a value, persists; Layer-2 gated. The
 * webhook URLs on this screen are secrets: loaded empty, stored encrypted.
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

        AdminConfig::where('key', self::KEY)->where('store_id', $this->storeId())->update(['value' => 'https://hooks.example.com/stored']);
        AdminConfig::forgetMemo();

        // WHY empty: a webhook URL is a secret — the screen never sends a stored one back to the browser.
        Livewire::test(GlobalConfigForm::class)
            ->assertSet('values.' . self::KEY, '');
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

        // WHY: no DNS in tests — the URL check on save resolves the host to a public address.
        OutboundUrlGuard::resolveUsing(fn () => ['93.184.216.34']);
        try {
            Livewire::test(GlobalConfigForm::class)
                ->set('values.' . self::KEY, 'https://hooks.example.com/zz')->call('save');
        } finally {
            OutboundUrlGuard::resolveUsing(null);
        }

        // WHY raw: the model accessor decrypts, the stored column must not hold the URL as typed.
        $stored = (string) AdminConfig::where('key', self::KEY)->where('store_id', $this->storeId())->first()->getRawOriginal('value');
        $this->assertNotSame('https://hooks.example.com/zz', $stored, 'stored encrypted, not as typed');
        $this->assertSame('https://hooks.example.com/zz', gp247_secret_decrypt($stored));
    }

    public function test_live_update_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(GlobalConfigForm::class);

        $this->assertDenied(fn () => $component->set('values.' . self::KEY, 'https://nope')->call('save'));
    }
}
