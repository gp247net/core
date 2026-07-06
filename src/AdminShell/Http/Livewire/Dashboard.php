<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\Models\AdminHome;
use Illuminate\Contracts\View\View;

/**
 * Modern admin dashboard (ADR-002 / ADR-005 / ADR-007). The landing screen of
 * the admin shell: renders the blocks configured in `admin_home_layout`
 * (view/size/sort/status, model AdminHome) inside a 12-column grid, each block
 * spanning `size` columns. Available blocks are store KPIs, order-trend
 * charts, latest orders/customers and a welcome panel.
 *
 * This component only discovers and lays out the configured blocks — each
 * block's Blade view queries its own data (guarded by class_exists/
 * method_exists against the optional shop package), so a block whose view
 * isn't registered (e.g. shop absent) is silently skipped and no other block
 * carries data it doesn't need.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-LW-001, US-UI-009
 * @aidlc-adr ADR-002, ADR-005, ADR-007
 */
class Dashboard extends GP247AdminComponent
{
    /**
     * Landing page for every authenticated admin: skip the Layer-2 permission
     * check (the legacy home had none) but keep the post-redirect flash relay.
     *
     * @return void
     */
    public function mount(): void
    {
        $this->flashNotice();
    }

    /**
     * Render the dashboard wrapped in the admin layout.
     *
     * @return View
     */
    public function render(): View
    {
        return view('gp247-admin::livewire.dashboard', ['blocks' => $this->blocks()])
            ->layout('gp247-admin::layouts.admin', [
                'title' => gp247_language_render('admin.dashboard.title'),
            ]);
    }

    /**
     * Configured admin_home blocks (ADR-007), in sort order, each carrying a
     * Tailwind column-span class derived from its configured `size` (1-12).
     * Blocks whose view isn't registered (e.g. the shop package isn't
     * installed) are skipped.
     *
     * @return array<int, array{view: string, spanClass: string}>
     */
    private function blocks(): array
    {
        return AdminHome::getBlockHome()
            ->filter(fn ($block) => view()->exists($block->view))
            ->map(fn ($block) => [
                'view' => $block->view,
                'spanClass' => 'xl:col-span-' . max(1, min(12, (int) $block->size)),
            ])
            ->values()
            ->all();
    }
}
