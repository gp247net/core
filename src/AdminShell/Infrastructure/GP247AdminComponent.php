<?php

namespace GP247\Core\AdminShell\Infrastructure;

use Livewire\Component;

/**
 * Abstract base for every GP247 admin Livewire component (ADR-001 / ADR-005).
 *
 * Wires Layer-2 authorization into the component lifecycle: read access is
 * checked on mount(), and concrete components call authorizeAction() at the top
 * of each mutating method. Subclasses set $permission (or rely on convention via
 * the resolved component name) and the toast/loading helpers added later (ADR-005).
 *
 * Livewire 4 is registered per package via component_namespaces (ADR-003); this
 * base lives in the core package's namespace and is reused by front/shop/plugins.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-LW-001
 * @aidlc-adr ADR-001
 */
abstract class GP247AdminComponent extends Component
{
    use AuthorizesAdminActions;

    /**
     * Permission slug gating this component. When null, the permission is
     * inferred from the component name by the PermissionResolver convention.
     *
     * @var string|null
     */
    protected ?string $permission = null;

    /**
     * Livewire lifecycle hook; enforces read authorization before the component
     * is shown. Subclasses that override mount() must call parent::mount().
     *
     * @return void
     */
    public function mount(): void
    {
        $this->authorizeView();
        $this->flashNotice();
    }

    /**
     * Surface a post-redirect "success" flash as a top-right toast, so redirect
     * screens get the same popup feedback without an inline, layout-shifting box.
     *
     * @return void
     */
    protected function flashNotice(): void
    {
        if (session()->has('gp247_admin_success')) {
            $this->notify('success', (string) session('gp247_admin_success'));
        }
    }

    /**
     * Resolve the Livewire component name for permission mapping.
     *
     * WHY: Livewire 4 exposes the registered name via getName(); it is the same
     * identifier used by component_namespaces (e.g. "gp247-core::product-list").
     *
     * @return string The registered component name.
     */
    protected function componentIdentifier(): string
    {
        return $this->getName();
    }

    /**
     * @return string|null The explicitly declared permission slug, if any.
     */
    protected function permissionKey(): ?string
    {
        return $this->permission;
    }

    /**
     * Emit a UI notification that the <x-gp247::toast> container renders.
     *
     * WHY: dispatched as a browser event so any component (and the persistent
     * layout) can surface toasts without a shared parent (ADR-005).
     *
     * @param string $type    One of info|success|warning|error.
     * @param string $message Human-readable message text.
     * @return void
     */
    protected function notify(string $type, string $message): void
    {
        $this->dispatch('notify', type: $type, message: $message);
    }
}
