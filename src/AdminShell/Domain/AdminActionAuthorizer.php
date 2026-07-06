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
     * Decide whether the user may perform the action.
     *
     * @param AdminUserContract  $user       Authenticated admin user.
     * @param PermissionKey|null $key         Resolved permission key, or null when
     *                                        the component/action could not be mapped.
     * @param bool               $isMutating  True when the action changes state
     *                                         (create/update/delete); false for reads.
     * @return AuthorizationDecision Allow or deny, with a stable reason.
     */
    public function authorize(AdminUserContract $user, ?PermissionKey $key, bool $isMutating): AuthorizationDecision
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

        // WHY: deny-by-default — an unmapped component/action must not slip
        // through the shared Livewire endpoint just because no key was found.
        if ($key === null) {
            return AuthorizationDecision::deny('unresolved_permission_key');
        }

        return $user->can($key->value())
            ? AuthorizationDecision::allow('granted_by_slug')
            : AuthorizationDecision::deny('missing_permission_slug');
    }
}
