<?php

namespace GP247\Core\AdminShell\Infrastructure;

use Livewire\Attributes\Locked;
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
     * Admin path of the screen this component was first mounted on (no scheme/host,
     * e.g. "gp247_admin/order"). Captured at mount() and persisted across Livewire
     * updates so mutating actions authorize against the origin screen — not the
     * shared "/livewire/update" endpoint. #[Locked] so the client cannot forge it.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $gp247AuthUri = null;

    /**
     * Canonical admin path of this screen (no scheme/host, e.g. "gp247_admin/order"),
     * used to authorize by the v1 URI+method model (ADR-001 Layer-2). When null, the
     * path is derived per base class (ResourcePanel from its route) or falls back to
     * the request path captured at mount. Concrete forms without a list route should
     * set this to their resource path so authorization is deterministic.
     *
     * @var string|null
     */
    protected ?string $screenUri = null;

    /**
     * Livewire lifecycle hook; enforces read authorization before the component
     * is shown. Subclasses that override mount() must call parent::mount().
     *
     * @return void
     */
    public function mount(): void
    {
        // Capture the origin screen path BEFORE authorizing, so both the view
        // check here and later mutating actions gate against the same URI.
        $this->gp247AuthUri = request()->path();
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
     *   Retained as a human-readable label only; access is decided by URI+method
     *   (ADR-001 Layer-2), not by this slug.
     */
    protected function permissionKey(): ?string
    {
        return $this->permission;
    }

    /**
     * The origin screen path captured at mount(), used to authorize by the v1
     * URI+method model. Falls back to the current request path when a component
     * authorizes before mount has run.
     *
     * @return string|null Screen path (e.g. "gp247_admin/order"), or null when unknown.
     */
    protected function authScreenUri(): ?string
    {
        if ($this->screenUri !== null && $this->screenUri !== '') {
            return $this->screenUri;
        }

        $prefix = defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin';
        $path   = ltrim((string) ($this->gp247AuthUri ?? request()->path()), '/');

        // Production: the captured request path IS the real admin screen URL, so
        // authorization matches the same http_uri the menu and PermissionMiddleware
        // use (ADR-001 Layer-2).
        if ($path !== '' && str_starts_with($path, $prefix)) {
            return $path;
        }

        // Fallback when no real admin request path is available (e.g. a Livewire
        // component invoked outside a route): reconstruct the resource path from the
        // declared permission label. The access decision itself is still http_uri —
        // this only identifies which screen the component represents.
        if (is_string($this->permission) && str_starts_with($this->permission, 'admin_')) {
            return $prefix . '/' . substr($this->permission, strlen('admin_'));
        }

        return $path !== '' ? $path : null;
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
