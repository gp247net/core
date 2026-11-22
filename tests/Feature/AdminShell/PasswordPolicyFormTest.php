<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\PasswordPolicyForm;
use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the ConfigForm key-subset feature via the password-policy screen
 * (ADR-001/005): only the admin_password_* keys load, edits persist, Layer-2 gated.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-001, ADR-005
 */
class PasswordPolicyFormTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private function storeId()
    {
        return defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
    }

    public function test_loads_only_password_keys(): void
    {
        $this->asUser(slugs: ['admin_config']);

        $component = Livewire::test(PasswordPolicyForm::class)
            ->assertSet('values.admin_password_min', AdminConfig::where('key', 'admin_password_min')->where('store_id', $this->storeId())->value('value'));

        // Keys outside the subset must not be loaded (e.g. a webhook key).
        $this->assertArrayNotHasKey('LOG_SLACK_WEBHOOK_URL', $component->get('values'));
    }

    public function test_saves_changed_value_live(): void
    {
        $this->asUser(slugs: ['admin_config']);

        Livewire::test(PasswordPolicyForm::class)
            ->set('values.admin_password_min', '10')->call('save');

        $this->assertSame(
            '10',
            AdminConfig::where('key', 'admin_password_min')->where('store_id', $this->storeId())->value('value'),
        );
    }

    public function test_toggling_bool_persists_one_zero(): void
    {
        $this->asUser(slugs: ['admin_config']);

        Livewire::test(PasswordPolicyForm::class)
            ->set('values.admin_password_number', true)->call('save');

        $this->assertSame(
            '1',
            AdminConfig::where('key', 'admin_password_number')->where('store_id', $this->storeId())->value('value'),
        );
    }

    public function test_live_update_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(PasswordPolicyForm::class);

        $this->assertDenied(fn () => $component->set('values.admin_password_min', '99')->call('save'));
    }
}
