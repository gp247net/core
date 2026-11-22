<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\Feature\AdminShell\Support\TestDataTableComponent;
use GP247\Core\Tests\TestCase;

/**
 * Exercises the DataTableComponent base through the real Livewire 4 lifecycle:
 * list rendering, sort toggling, keyword-driven page reset, and Layer-2
 * authorization on the mutating bulk-delete action (ADR-001 / ADR-005).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002
 * @aidlc-adr ADR-001, ADR-005
 */
class DataTableComponentTest extends TestCase
{
    use InteractsWithAdminUser;

    public function test_renders_rows_for_authorized_user(): void
    {
        $this->asUser(slugs: ['admin_test']);

        Livewire::test(TestDataTableComponent::class)
            ->assertSee('Alpha')
            ->assertSee('Bravo');
    }

    public function test_set_sort_toggles_direction_on_same_field(): void
    {
        $this->asUser(slugs: ['admin_test']);

        Livewire::test(TestDataTableComponent::class)
            ->call('setSort', 'name')
            ->assertSet('sortField', 'name')
            ->assertSet('sortDir', 'asc')
            ->call('setSort', 'name')
            ->assertSet('sortDir', 'desc');
    }

    public function test_updating_keyword_resets_to_first_page(): void
    {
        $this->asUser(slugs: ['admin_test']);

        Livewire::test(TestDataTableComponent::class)
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2)
            ->set('keyword', 'Al')
            ->assertSet('paginators.page', 1);
    }

    public function test_bulk_delete_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(TestDataTableComponent::class)->set('selected', [1, 2]);

        $this->assertDenied(fn () => $component->call('bulkDelete'));
    }

    public function test_bulk_delete_allowed_with_matching_slug(): void
    {
        $this->asUser(slugs: ['admin_test']);

        Livewire::test(TestDataTableComponent::class)
            ->set('selected', [1, 2])
            ->call('bulkDelete')
            ->assertSet('deletedIds', [1, 2])
            ->assertSet('selected', []);
    }
}
