<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\CustomFieldList;
use GP247\Core\Models\AdminCustomField;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the custom field list (ADR-001/005): listing, delete, deny.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005
 */
class CustomFieldListTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private function seedField(string $code = 'zz_field'): AdminCustomField
    {
        return AdminCustomField::create([
            'type' => 'shop_product',
            'name' => 'ZZ ' . $code,
            'code' => $code,
            'option' => '',
            'default' => '',
            'required' => 0,
            'status' => 1,
        ]);
    }

    public function test_lists_custom_fields(): void
    {
        $this->asUser(slugs: ['admin_custom_field']);
        $field = $this->seedField();

        Livewire::test(CustomFieldList::class)->assertSee($field->name);
    }

    public function test_single_delete_removes_field(): void
    {
        $this->asUser(slugs: ['admin_custom_field']);
        $field = $this->seedField();

        Livewire::test(CustomFieldList::class)->call('delete', $field->id);

        $this->assertFalse(AdminCustomField::where('id', $field->id)->exists());
    }

    public function test_bulk_delete_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $field = $this->seedField();

        $component = Livewire::test(CustomFieldList::class)->set('selected', [$field->id]);

        $this->assertDenied(fn () => $component->call('bulkDelete'));
        $this->assertTrue(AdminCustomField::where('id', $field->id)->exists());
    }
}
