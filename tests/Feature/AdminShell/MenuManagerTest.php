<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\MenuManager;
use GP247\Core\Models\AdminMenu;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the two-panel menu manager (ADR-001/002/005): tree listing, create,
 * validation, leaf delete, the has-children delete guard, drag reorder, edit
 * deep-link, and Layer-2 authorization.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003, US-UI-007
 * @aidlc-adr ADR-001, ADR-002, ADR-005
 */
class MenuManagerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private function seedMenu(string $title, int $parentId = 0): AdminMenu
    {
        return AdminMenu::create(['title' => $title, 'parent_id' => $parentId, 'uri' => '', 'icon' => '', 'sort' => 0]);
    }

    public function test_lists_menu_nodes(): void
    {
        $this->asUser(slugs: ['admin_menu']);
        $this->seedMenu('ZZ Menu Node');

        Livewire::test(MenuManager::class)->assertSee('ZZ Menu Node');
    }

    public function test_creates_menu_node(): void
    {
        $this->asUser(slugs: ['admin_menu']);

        $component = Livewire::test(MenuManager::class)
            ->set('form.title', 'ZZ New Menu')
            ->set('form.uri', 'admin::admin-shell/languages')
            ->set('form.icon', 'fas fa-language')
            ->set('form.sort', 5)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $created = AdminMenu::where('title', 'ZZ New Menu')->first();
        $this->assertNotNull($created);
        // US-AUI-two-panel-state-preservation: stay on the screen, editing the new node.
        $component->assertSet('editingId', (string) $created->id)
            ->assertSet('form.title', 'ZZ New Menu');
    }

    /**
     * @aidlc-story US-AUI-two-panel-state-preservation
     */
    public function test_edit_row_loads_the_node_in_place_and_highlights_it(): void
    {
        $this->asUser(slugs: ['admin_menu']);
        $node = $this->seedMenu('ZZ Inline Edit');

        Livewire::test(MenuManager::class)
            ->call('editRow', (string) $node->id)
            ->assertNoRedirect()
            ->assertSet('editingId', (string) $node->id)
            ->assertSet('form.title', 'ZZ Inline Edit')
            ->assertSeeHtml('aria-current="true"');
    }

    /**
     * @aidlc-story US-AUI-two-panel-state-preservation
     */
    public function test_saving_an_edit_stays_on_the_screen_with_the_saved_node(): void
    {
        $this->asUser(slugs: ['admin_menu']);
        $node = $this->seedMenu('ZZ Before');

        Livewire::test(MenuManager::class)
            ->call('editRow', (string) $node->id)
            ->set('form.title', 'ZZ After')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSet('editingId', (string) $node->id)
            ->assertSet('form.title', 'ZZ After');

        $this->assertSame('ZZ After', AdminMenu::find($node->id)->title);
    }

    /**
     * @aidlc-story US-AUI-two-panel-state-preservation
     */
    public function test_cancel_edit_clears_the_form_without_redirect(): void
    {
        $this->asUser(slugs: ['admin_menu']);
        $node = $this->seedMenu('ZZ Cancel');

        Livewire::test(MenuManager::class)
            ->call('editRow', (string) $node->id)
            ->call('cancelEdit')
            ->assertNoRedirect()
            ->assertSet('editingId', null)
            ->assertSet('form.title', '');
    }

    public function test_title_is_required(): void
    {
        $this->asUser(slugs: ['admin_menu']);

        Livewire::test(MenuManager::class)
            ->set('form.title', '')
            ->call('save')
            ->assertHasErrors('form.title');
    }

    public function test_mount_loads_node_for_editing(): void
    {
        $this->asUser(slugs: ['admin_menu']);
        $node = $this->seedMenu('ZZ Editable');

        Livewire::test(MenuManager::class, ['id' => (string) $node->id])
            ->assertSet('editingId', (string) $node->id)
            ->assertSet('form.title', 'ZZ Editable');
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(MenuManager::class)->set('form.title', 'ZZ New Menu');

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(AdminMenu::where('title', 'ZZ New Menu')->exists());
    }

    public function test_single_delete_removes_leaf(): void
    {
        $this->asUser(slugs: ['admin_menu']);
        $node = $this->seedMenu('ZZ Leaf');

        Livewire::test(MenuManager::class)->call('delete', $node->id);

        $this->assertFalse(AdminMenu::where('id', $node->id)->exists());
    }

    public function test_delete_blocked_when_node_has_children(): void
    {
        $this->asUser(slugs: ['admin_menu']);
        $parent = $this->seedMenu('ZZ Parent');
        $this->seedMenu('ZZ Child', $parent->id);

        Livewire::test(MenuManager::class)->call('delete', $parent->id);

        $this->assertTrue(AdminMenu::where('id', $parent->id)->exists());
    }

    public function test_reorder_updates_sort_and_parent(): void
    {
        $this->asUser(slugs: ['admin_menu']);
        $a = $this->seedMenu('ZZ A');
        $b = $this->seedMenu('ZZ B');

        // Drop B above A under root. The tree/sidebar order by sort DESC, so the
        // topmost item (B) gets the highest sort: B = 2, A = 1.
        Livewire::test(MenuManager::class)->call('reorder', 0, [$b->id, $a->id]);

        $this->assertSame(2, (int) AdminMenu::find($b->id)->sort);
        $this->assertSame(1, (int) AdminMenu::find($a->id)->sort);
    }

    public function test_reorder_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $a = $this->seedMenu('ZZ A');

        $component = Livewire::test(MenuManager::class);

        $this->assertDenied(fn () => $component->call('reorder', 0, [$a->id]));
    }

    public function test_delete_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $node = $this->seedMenu('ZZ Guarded');

        $component = Livewire::test(MenuManager::class);

        $this->assertDenied(fn () => $component->call('delete', $node->id));
        $this->assertTrue(AdminMenu::where('id', $node->id)->exists());
    }
}
