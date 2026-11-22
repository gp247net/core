<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\HomeLayoutList;
use GP247\Core\Models\AdminHome;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the home-layout blocks list (ADR-001/005): listing, delete, deny.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002
 * @aidlc-adr ADR-001, ADR-005
 */
class HomeLayoutListTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * Seed a single home-layout block row.
     *
     * @param string $view Block view id.
     * @return AdminHome
     */
    private function seedBlock(string $view = 'gp247-admin::component.home_default'): AdminHome
    {
        return AdminHome::create([
            'view' => $view,
            'size' => 12,
            'sort' => 10,
            'extension' => explode('::', $view)[0],
            'status' => 1,
        ]);
    }

    public function test_lists_blocks(): void
    {
        $this->asUser(slugs: ['admin_home_layout']);
        $block = $this->seedBlock();

        Livewire::test(HomeLayoutList::class)->assertSee($block->view);
    }

    public function test_single_delete_removes_block(): void
    {
        $this->asUser(slugs: ['admin_home_layout']);
        $block = $this->seedBlock();

        Livewire::test(HomeLayoutList::class)->call('delete', $block->id);

        $this->assertFalse(AdminHome::where('id', $block->id)->exists());
    }

    public function test_bulk_delete_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $block = $this->seedBlock();

        $component = Livewire::test(HomeLayoutList::class)->set('selected', [$block->id]);

        $this->assertDenied(fn () => $component->call('bulkDelete'));
        $this->assertTrue(AdminHome::where('id', $block->id)->exists());
    }
}
