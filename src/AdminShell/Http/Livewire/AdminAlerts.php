<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\AdminShell\Support\AdminAlertService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * "System alerts" dashboard block: a one-line summary — worst level, counts per level,
 * the names of at most two critical checks and a link to the "System alerts" page, where
 * the full list, details and "Check again" live (ADR admin-shell_system-alerts-page).
 *
 * Lazy so the rest of the dashboard paints first (NFR-PERF-admin-alert-non-blocking).
 * Administrator only, enforced on mount and on every action — the block view
 * already renders nothing for other roles, but a crafted Livewire request must be
 * refused too (NFR-SEC-admin-alert-administrator-only).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 * @aidlc-adr admin-shell_system-alert-registry
 * @aidlc-adr admin-shell_system-alerts-page
 */
#[Lazy]
class AdminAlerts extends Component
{
    /** Critical checks named in the summary line; the rest are counted. */
    private const NAMED_CRITICAL = 2;

    /**
     * @return void
     * @throws AuthorizationException When the user is not an Administrator.
     */
    public function mount(): void
    {
        $this->authorizeAdministrator();
    }

    /**
     * Shown while the lazy request is in flight.
     *
     * @return string
     */
    public function placeholder(): string
    {
        return view('gp247-admin::livewire.admin-alerts-placeholder')->render();
    }

    /**
     * @return View
     */
    public function render(): View
    {
        $this->authorizeAdministrator();
        $result = app(AdminAlertService::class)->results();
        $failing = array_values(array_filter($result['items'], static fn (AdminAlert $a) => !$a->passed()));
        $critical = array_values(array_filter($failing, static fn (AdminAlert $a) => $a->level === AdminAlert::CRITICAL));

        return view('gp247-admin::livewire.admin-alerts', [
            // Items are sorted by weight, so the first failing one carries the worst level.
            'worst' => $failing[0]->level ?? AdminAlert::OK,
            'counts' => array_count_values(array_map(static fn (AdminAlert $a) => $a->level, $failing)),
            'failingCount' => count($failing),
            'criticalNames' => array_map(static fn (AdminAlert $a) => $a->message, array_slice($critical, 0, self::NAMED_CRITICAL)),
            'criticalMore' => max(0, count($critical) - self::NAMED_CRITICAL),
            'total' => count($result['items']),
            'checkedAt' => $result['checkedAt'],
        ]);
    }

    /**
     * @return void
     * @throws AuthorizationException
     */
    private function authorizeAdministrator(): void
    {
        if (!app(AdminAlertService::class)->allowed()) {
            throw AuthorizationException::fromReason('system alerts are shown to administrators only');
        }
    }
}
