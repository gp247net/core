<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\CustomFieldForm;
use GP247\Core\Models\AdminCustomField;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the custom field form (ADR-001/005): authorization, validation, and
 * persist with a slugified code.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005
 */
class CustomFieldFormTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    public function test_creates_custom_field_with_slugified_code(): void
    {
        $this->asUser(slugs: ['admin_custom_field']);

        Livewire::test(CustomFieldForm::class)
            ->set('form.type', 'shop_product')
            ->set('form.name', 'ZZ Extra Note')
            ->set('form.code', 'ZZ Extra Note')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin_custom_field.index'));

        $field = AdminCustomField::where('name', 'ZZ Extra Note')->first();
        $this->assertNotNull($field);
        $this->assertSame(gp247_word_limit(gp247_word_format_url('ZZ Extra Note'), 100), $field->code);
    }

    public function test_validates_required_fields(): void
    {
        $this->asUser(slugs: ['admin_custom_field']);

        Livewire::test(CustomFieldForm::class)
            ->set('form.type', '')
            ->set('form.name', '')
            ->set('form.code', '')
            ->call('save')
            ->assertHasErrors(['form.type', 'form.name', 'form.code']);
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(CustomFieldForm::class)
            ->set('form.type', 'shop_product')
            ->set('form.name', 'ZZ Extra Note')
            ->set('form.code', 'zz_extra');

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(AdminCustomField::where('name', 'ZZ Extra Note')->exists());
    }
}
