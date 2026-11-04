<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\AdminShell\Support\AdminAlertService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "System alerts" page: every check grouped by level with its technical detail, a
 * level filter (browser side) and "Check again". Administrator only — the route path is
 * in PermissionMiddleware::administratorOnly() and this component gates itself too.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts-page
 * @aidlc-adr admin-shell_system-alerts-page
 */
class SystemAlerts extends Component
{
    /**
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function mount(): void
    {
        $this->authorizeAdministrator();
    }

    /**
     * Drop every cached result and evaluate again.
     *
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function recheck(): void
    {
        $this->authorizeAdministrator();
        app(AdminAlertService::class)->refresh();
    }

    /**
     * @return View
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function render(): View
    {
        $this->authorizeAdministrator();
        $result = app(AdminAlertService::class)->results();

        $groups = ['critical' => [], 'warning' => [], 'info' => [], 'ok' => []];
        foreach ($result['items'] as $alert) {
            $groups[$alert->level][] = $alert;
        }

        return view('gp247-admin::livewire.system-alerts', [
            'groups' => $groups,
            'counts' => [
                'all' => count($result['items']),
                'critical' => count($groups['critical']),
                // "To review" covers warnings and information items (one filter, two groups).
                'warning' => count($groups['warning']) + count($groups['info']),
                'ok' => count($groups['ok']),
            ],
            'checkedAt' => $result['checkedAt'],
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('admin.alerts.title'),
        ]);
    }

    /**
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    private function authorizeAdministrator(): void
    {
        if (!app(AdminAlertService::class)->allowed()) {
            throw AuthorizationException::fromReason('system alerts are shown to administrators only');
        }
    }
}
