<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Infrastructure\ConfigForm;
use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * A config screen may show a setting it will not let this admin edit — because
 * unlocking it takes a licence, an add-on or a paid edition. Hiding such a
 * setting is the one thing a plugin must not do to a setting it wants the admin
 * to want; ConfigForm::lockedKeys() is the seam that keeps it visible, disabled,
 * explained, and never written.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-story US-admin-shell-locked-config-effective-state
 */
class ConfigFormLockedKeysTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private const GROUP = 'probe_locked_keys';

    protected function setUp(): void
    {
        parent::setUp();
        $storeId = (string) (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0);
        AdminConfig::where('group', self::GROUP)->delete();
        AdminConfig::insert([
            ['group' => self::GROUP, 'code' => 'probe', 'key' => 'probe_free_key', 'sort' => 1, 'store_id' => $storeId, 'value' => 'free-old', 'detail' => 'Free key'],
            ['group' => self::GROUP, 'code' => 'probe', 'key' => 'probe_locked_key', 'sort' => 2, 'store_id' => $storeId, 'value' => 'locked-old', 'detail' => 'Locked key'],
            ['group' => self::GROUP, 'code' => 'probe', 'key' => 'probe_locked_switch', 'sort' => 3, 'store_id' => $storeId, 'value' => '1', 'detail' => 'Locked switch'],
            ['group' => self::GROUP, 'code' => 'probe', 'key' => 'probe_locked_choice', 'sort' => 4, 'store_id' => $storeId, 'value' => 'full', 'detail' => 'Locked choice'],
        ]);
    }

    public function test_a_locked_key_is_shown_disabled_with_its_reason_and_a_way_to_unlock(): void
    {
        $this->asUser(slugs: ['admin_config']);

        $html = Livewire::test(LockedKeysProbeForm::class)->html();

        $this->assertStringContainsString('data-testid="config-form-locked-probe_locked_key"', $html, 'the locked key still renders');
        $this->assertStringContainsString('<fieldset disabled', $html, 'its input is disabled');
        $this->assertStringContainsString('Needs the Pro edition', $html, 'the reason is spelled out');
        $this->assertStringContainsString('https://example.test/unlock', $html, 'and the way to unlock it is offered');
        $this->assertStringNotContainsString('data-testid="config-form-locked-probe_free_key"', $html, 'an editable key carries no lock');
    }

    public function test_save_writes_the_editable_key_and_never_the_locked_one(): void
    {
        $this->asUser(slugs: ['admin_config']);

        Livewire::test(LockedKeysProbeForm::class)
            ->set('values.probe_free_key', 'free-new')
            ->set('values.probe_locked_key', 'locked-new')
            ->call('save');

        $this->assertSame('free-new', AdminConfig::where('group', self::GROUP)->where('key', 'probe_free_key')->value('value'));
        $this->assertSame('locked-old', AdminConfig::where('group', self::GROUP)->where('key', 'probe_locked_key')->value('value'), 'a locked key is displayed, never written');
    }

    /**
     * A locked widget used to render the value sitting in admin_config, which on
     * a downgraded site is whatever the paid edition last saved — so a switch the
     * feature gate is blocking still showed a tick, and the screen told the admin
     * they already had the feature. What it must show is the state actually in
     * effect, which only the feature's owner can name: false for a switch, but
     * the preset the free edition really runs for a choice.
     *
     * @aidlc-story US-admin-shell-locked-config-effective-state
     */
    public function test_a_locked_key_shows_the_state_that_is_actually_in_effect(): void
    {
        $this->asUser(slugs: ['admin_config']);

        $form = Livewire::test(LockedKeysProbeForm::class);

        $this->assertFalse($form->get('values.probe_locked_switch'), 'the locked switch shows the effective state, not the stored 1');
        $this->assertSame('basic', $form->get('values.probe_locked_choice'), 'the locked choice shows the preset actually in effect');
        $this->assertSame('1', AdminConfig::where('group', self::GROUP)->where('key', 'probe_locked_switch')->value('value'), 'the stored value is untouched — unlocking brings it back');
    }

    /**
     * The seam predates the effective-state contract, so a lock that names no
     * value must keep behaving exactly as before: no consumer gets a silent
     * change in what its screen displays.
     *
     * @aidlc-story US-admin-shell-locked-config-effective-state
     */
    public function test_a_lock_without_an_effective_value_keeps_showing_the_stored_value(): void
    {
        $this->asUser(slugs: ['admin_config']);

        $this->assertSame(
            'locked-old',
            Livewire::test(LockedKeysProbeForm::class)->get('values.probe_locked_key'),
            'a lock with no value declared is backwards compatible'
        );
    }
}

/**
 * Minimal ConfigForm with one editable and one locked key (test double).
 */
class LockedKeysProbeForm extends ConfigForm
{
    protected ?string $permission = 'admin_config';

    protected function group(): string
    {
        return 'probe_locked_keys';
    }

    protected function heading(): string
    {
        return 'Probe';
    }

    protected function keys(): array
    {
        return ['probe_free_key', 'probe_locked_key', 'probe_locked_switch', 'probe_locked_choice'];
    }

    protected function fieldTypes(): array
    {
        return [
            'probe_free_key' => 'text',
            'probe_locked_key' => 'text',
            'probe_locked_switch' => 'bool',
            'probe_locked_choice' => 'select',
        ];
    }

    protected function fieldOptions(): array
    {
        return ['probe_locked_choice' => ['basic' => 'Basic', 'full' => 'Full']];
    }

    protected function lockedKeys(): array
    {
        return [
            // No 'value': the pre-existing contract, kept working as-is.
            'probe_locked_key' => ['hint' => 'Needs the Pro edition', 'url' => 'https://example.test/unlock', 'label' => 'Unlock'],
            'probe_locked_switch' => ['hint' => 'Needs the Pro edition', 'value' => false],
            'probe_locked_choice' => ['hint' => 'Needs the Pro edition', 'value' => 'basic'],
        ];
    }
}
