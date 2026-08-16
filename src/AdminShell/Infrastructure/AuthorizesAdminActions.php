<?php

namespace GP247\Core\AdminShell\Infrastructure;

use GP247\Core\AdminShell\Application\AuthorizeAdminAction;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Domain\AuthorizationException;

/**
 * Layer-2 authorization trait for admin Livewire components (ADR-001).
 *
 * Provides defense-in-depth on top of the global LivewireAuthGuard: the base
 * component authorizes the view on mount() and each mutating action re-checks
 * before it runs. Components declare their permission via $permission and their
 * identity via componentIdentifier(); both are supplied by GP247AdminComponent.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
trait AuthorizesAdminActions
{
    /**
     * Authorize read access to the component (typically called from mount()).
     *
     * @return void
     * @throws AuthorizationException When the current user may not view the component.
     */
    protected function authorizeView(): void
    {
        $this->authorizeAdminMethod('mount');
    }

    /**
     * Authorize a specific action method before it mutates state.
     *
     * @param string $method The action method name (e.g. "delete", "save").
     * @return void
     * @throws AuthorizationException When the current user may not perform the action.
     */
    protected function authorizeAction(string $method): void
    {
        $this->authorizeAdminMethod($method);
    }

    /**
     * Run the shared authorization use case for the given method and abort on deny.
     *
     * @param string $method The method being authorized.
     * @return void
     * @throws AuthorizationException When the decision denies the action.
     */
    private function authorizeAdminMethod(string $method): void
    {
        /** @var AuthorizeAdminAction $useCase */
        $useCase = app(AuthorizeAdminAction::class);

        // WHY: resolve the current admin user through the container (bound in
        // AdminShellServiceProvider) so tests can inject a fake without the DB.
        $decision = $useCase->authorize(
            app(AdminUserContract::class),
            $this->authScreenUri(),
            $method,
        );

        if (!$decision->isAllowed()) {
            throw AuthorizationException::fromReason($decision->reason());
        }
    }

    /**
     * The admin path of the screen this component belongs to (no scheme/host),
     * used to authorize by the v1 URI+method model (ADR-001 Layer-2).
     *
     * @return string|null Screen path (e.g. "gp247_admin/order"), or null when unknown.
     */
    abstract protected function authScreenUri(): ?string;
}
