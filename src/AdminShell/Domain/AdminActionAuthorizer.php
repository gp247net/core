<?php

namespace GP247\Core\AdminShell\Domain;

/**
 * Core authorization decision for an admin action (Layer-1 + Layer-2 logic,
 * ADR-001). Pure and side-effect free: given a user, a resolved permission key
 * and whether the action mutates state, it returns an allow/deny decision.
 *
 * Preserves the brownfield RBAC semantics from GP247\Core (PermissionMiddleware
 * + AdminUser): administrator bypasses everything; view.all may read but never
 * mutate; otherwise a matching permission slug is required. Anything that cannot
 * be resolved to a permission key is denied (secure default), which is what
 * closes the shared "/livewire/update" endpoint gap (RISK-TECH-001).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
final class AdminActionAuthorizer
{
    /**
     * Decide whether the user may perform the action, using the v1 URI+method model
     * (ADR-001 Layer-2): access is decided by the screen path against the user's
     * permission `http_uri` catalog — the permission slug is only a label. A view
     * maps to GET (same as menu visibility); a mutation maps to POST.
     *
     * @param AdminUserContract $user        Authenticated admin user.
     * @param string|null       $screenUri   Admin path of the screen (no scheme/host),
     *                                        or null when it could not be determined.
     * @param bool              $isMutating  True when the action changes state
     *                                        (create/update/delete); false for reads.
     * @return AuthorizationDecision Allow or deny, with a stable reason.
     */
    public function authorize(AdminUserContract $user, ?string $screenUri, bool $isMutating): AuthorizationDecision
    {
        if ($user->isAdministrator()) {
            return AuthorizationDecision::allow('administrator');
        }

        if ($user->isViewAll()) {
            // WHY: brownfield "view.all" role can browse every screen (GET) but
            // must never change data; mutating Livewire actions are blocked here.
            return $isMutating
                ? AuthorizationDecision::deny('view_all_cannot_mutate')
                : AuthorizationDecision::allow('view_all_read');
        }

        // WHY: deny-by-default — an action whose screen path cannot be resolved
        // must not slip through the shared Livewire endpoint.
        if ($screenUri === null || trim($screenUri) === '') {
            return AuthorizationDecision::deny('unresolved_screen_uri');
        }

        return $user->canAccessUrl($screenUri, $isMutating ? 'POST' : 'GET')
            ? AuthorizationDecision::allow('granted_by_uri')
            : AuthorizationDecision::deny('missing_permission_uri');
    }
}
