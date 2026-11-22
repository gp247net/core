<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\LanguageManager;
use GP247\Core\Models\AdminLanguage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the two-panel Language manager (ADR-005): list + inline add/edit/delete
 * on one page, against the live schema (rolled back). Exercises the ResourcePanel
 * base behaviour and Layer-2 authorization.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002
 * @aidlc-adr ADR-001, ADR-005
 */
class LanguageManagerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private function seedLanguage(string $code = 'zz', string $name = 'Testish'): AdminLanguage
    {
        return AdminLanguage::create([
            'name' => $name, 'code' => $code, 'icon' => '', 'status' => 1, 'rtl' => 0, 'sort' => 99,
        ]);
    }

    public function test_lists_languages(): void
    {
        $this->asUser(slugs: ['admin_language']);
        $lang = $this->seedLanguage();

        Livewire::test(LanguageManager::class)->assertSee($lang->name);
    }

    public function test_edit_row_loads_form(): void
    {
        $this->asUser(slugs: ['admin_language']);
        $lang = $this->seedLanguage();

        Livewire::test(LanguageManager::class)
            ->call('editRow', $lang->id)
            ->assertSet('editingId', (string) $lang->id)
            ->assertSet('form.name', $lang->name);
    }

    public function test_edit_state_restored_from_route_param(): void
    {
        // {id}/edit route segment must rehydrate the form on mount (refresh/deep link).
        $this->asUser(slugs: ['admin_language']);
        $lang = $this->seedLanguage();

        Livewire::test(LanguageManager::class, ['id' => (string) $lang->id])
            ->assertSet('editingId', (string) $lang->id)
            ->assertSet('form.name', $lang->name);
    }

    public function test_save_creates_and_switches_to_editing_the_new_language(): void
    {
        // US-AUI-two-panel-state-preservation: a create ends in
        // edit mode of the new record, never in add mode with the old input.
        $this->asUser(slugs: ['admin_language']);

        $component = Livewire::test(LanguageManager::class)
            ->set('form.name', 'Testish')
            ->set('form.code', 'zz')
            ->set('form.icon', 'GP247/Core/images/no-image.jpg')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $created = AdminLanguage::where('code', 'zz')->first();
        $this->assertNotNull($created);
        $component->assertSet('editingId', (string) $created->id)
            ->assertSet('form.code', 'zz');
    }

    public function test_save_requires_icon(): void
    {
        $this->asUser(slugs: ['admin_language']);

        Livewire::test(LanguageManager::class)
            ->set('form.name', 'X')
            ->set('form.code', 'zz')
            ->set('form.icon', '')
            ->call('save')
            ->assertHasErrors('form.icon');
    }

    public function test_delete_removes_language(): void
    {
        $this->asUser(slugs: ['admin_language']);
        $lang = $this->seedLanguage();

        Livewire::test(LanguageManager::class)->call('delete', $lang->id);

        $this->assertFalse(AdminLanguage::where('id', $lang->id)->exists());
    }

    public function test_delete_keeps_guarded_language(): void
    {
        $this->asUser(slugs: ['admin_language']);

        Livewire::test(LanguageManager::class)->call('delete', 1);

        $this->assertTrue(AdminLanguage::where('id', 1)->exists());
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(LanguageManager::class)
            ->set('form.name', 'Testish')
            ->set('form.code', 'zz')
            ->set('form.icon', 'x');

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(AdminLanguage::where('code', 'zz')->exists());
    }
}
