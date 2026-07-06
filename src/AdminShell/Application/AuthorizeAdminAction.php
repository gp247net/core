<?php

namespace GP247\Core\AdminShell\Application;

use GP247\Core\AdminShell\Domain\AdminActionAuthorizer;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Domain\AuthorizationDecision;

/**
 * Application use case wiring permission resolution and mutation classification
 * into the authorization core (ADR-001). Both the Layer-1 middleware
 * (LivewireAuthGuard) and the Layer-2 component trait call this single entry
 * point so the decision is identical from either side.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
final class AuthorizeAdminAction
{
    /**
     * Methods that never change state. Anything not listed is treated as
     * mutating, so the gate fails safe toward deny for view.all users.
     *
     * @var string[]
     */
    private const DEFAULT_READ_ONLY_METHODS = [
        // Livewire lifecycle hooks
        'mount', 'boot', 'booted', 'hydrate', 'dehydrate',
        'rendering', 'rendered', 'render', 'updating', 'updated',
        // Common read interactions on list/detail screens
        'setSort', 'setKeyword', 'setPaginate', 'search', 'applyFilter',
        'resetFilters', 'gotoPage', 'nextPage', 'previousPage', 'resetPage',
    ];

    /** @var string[] */
    private array $readOnlyMethods;

    /**
     * @param PermissionResolver     $resolver         Maps component identifiers to permission keys.
     * @param AdminActionAuthorizer  $authorizer       Pure authorization decision core.
     * @param string[]|null          $readOnlyMethods  Override the read-only method list, if needed.
     */
    public function __construct(
        private PermissionResolver $resolver,
        private AdminActionAuthorizer $authorizer,
        ?array $readOnlyMethods = null,
    ) {
        $this->readOnlyMethods = $readOnlyMethods ?? self::DEFAULT_READ_ONLY_METHODS;
    }

    /**
     * Authorize a single component action.
     *
     * @param AdminUserContract $user               Authenticated admin user.
     * @param string            $component          Component identifier (e.g. "gp247-core::product-list").
     * @param string            $action             Method being invoked (e.g. "mount", "delete").
     * @param string|null       $explicitPermission Permission slug declared by the component, if any.
     * @return AuthorizationDecision Allow or deny, with a stable reason.
     */
    public function authorize(
        AdminUserContract $user,
        string $component,
        string $action,
        ?string $explicitPermission = null,
    ): AuthorizationDecision {
        $isMutating = !in_array($action, $this->readOnlyMethods, true);
        $key = $this->resolver->resolve($component, $explicitPermission);

        return $this->authorizer->authorize($user, $key, $isMutating);
    }

    /**
     * Whether the given action method is classified as state-changing.
     *
     * @param string $action Method name.
     * @return bool True when the action is mutating.
     */
    public function isMutating(string $action): bool
    {
        return !in_array($action, $this->readOnlyMethods, true);
    }
}
