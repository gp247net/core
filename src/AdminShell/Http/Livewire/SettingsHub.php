<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Application\AuthorizeAdminAction;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\AdminShell\Support\SettingsHubTabRegistry;
use Illuminate\Contracts\View\View;

/**
 * Configuration hub (ADR-005): a single screen that groups the related settings
 * screens — General, Email/SMTP, Custom — into tabs, mirroring the legacy tabbed
 * config layout. Each tab embeds an existing config Livewire component as a nested
 * child, so their live-edit behaviour is reused unchanged.
 *
 * Other packages contribute extra tabs through SettingsHubTabRegistry (ADR
 * admin-shell_config-hub-tab-registry): shop plugs its ShopConfigForm in when
 * installed, so every configuration screen lives on this one hub. Core never
 * references those packages — it only renders opaque registered tuples.
 *
 * Authorization (ADR-001 Layer-2): the core tabs require `admin_config`; each
 * contributed tab is gated by its own `authUri`. Because every pane renders
 * up-front (Alpine toggles visibility), a tab the viewer cannot access is NOT
 * embedded at all — otherwise the child's mount() would throw and break the whole
 * page (RISK-SEC-config-rbac-uri-collapse). Entry is inclusive: the hub opens when
 * the viewer can see the core config OR at least one contributed tab, so a role
 * scoped only to a contributed screen (redirected here from its old URL) still
 * gets in and sees just that tab.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005, US-UI-008, US-admin-shell-config-hub-tab-registry
 * @aidlc-adr ADR-001, ADR-005, admin-shell_config-hub-tab-registry
 */
class SettingsHub extends GP247AdminComponent
{
    protected ?string $permission = 'admin_config';

    /**
     * Enforce inclusive read authorization: allow when the viewer can see the core
     * config screen OR any contributed tab; otherwise fall through to the standard
     * Layer-2 denial. Overrides the base mount() (which would hard-require
     * `admin_config`) so contributed-only roles are not locked out.
     *
     * @return void
     */
    public function mount(): void
    {
        // Capture the origin screen path (kept for parity with the base component)
        // and surface any post-redirect success flash as a toast.
        $this->gp247AuthUri = request()->path();
        $this->flashNotice();

        if (!$this->coreConfigAllowed() && $this->visibleExtraTabs() === []) {
            // No core config and no contributed tab visible → deny with the standard
            // Layer-2 exception (authorizes this screen and throws).
            $this->authorizeView();
        }
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('gp247-admin::livewire.settings-hub', [
            'coreAllowed' => $this->coreConfigAllowed(),
            'extraTabs'   => $this->visibleExtraTabs(),
        ])->layout('gp247-admin::layouts.admin', ['title' => gp247_language_render('admin.cfg_title')]);
    }

    /**
     * Whether the current viewer may see the core config tabs (General/Email/Custom).
     *
     * @return bool
     */
    private function coreConfigAllowed(): bool
    {
        return $this->canView($this->adminPath('store_config'));
    }

    /**
     * The contributed tabs the current viewer is authorized to see, in registry order.
     *
     * @return array<int, array{key:string, label:string, component:string, authUri:string, order:int}>
     */
    private function visibleExtraTabs(): array
    {
        return array_values(array_filter(
            SettingsHubTabRegistry::all(),
            fn (array $tab): bool => $this->canView($tab['authUri']),
        ));
    }

    /**
     * Non-throwing Layer-2 read check for an admin screen path, reusing the same
     * authorization use case the child components enforce on mount.
     *
     * @param string $screenUri Admin path (e.g. "gp247_admin/shop_config").
     * @return bool True when the current admin user may view the screen.
     */
    private function canView(string $screenUri): bool
    {
        return app(AuthorizeAdminAction::class)
            ->authorize(app(AdminUserContract::class), $screenUri, 'mount')
            ->isAllowed();
    }

    /**
     * Build an admin path from a resource segment using the configured admin prefix.
     *
     * @param string $resource Resource segment (e.g. "store_config").
     * @return string Admin path (e.g. "gp247_admin/store_config").
     */
    private function adminPath(string $resource): string
    {
        $prefix = defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin';

        return $prefix . '/' . $resource;
    }
}
