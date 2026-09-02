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
     * Optional veto fence injected directly (tests / explicit wiring). When null the
     * fence is read from config('gp247-config.admin.action_fence') at call time.
     *
     * @var callable|null
     */
    private $fence;

    /**
     * @param PermissionResolver     $resolver         Maps component identifiers to permission keys.
     * @param AdminActionAuthorizer  $authorizer       Pure authorization decision core.
     * @param string[]|null          $readOnlyMethods  Override the read-only method list, if needed.
     */
    public function __construct(
        private PermissionResolver $resolver,
        private AdminActionAuthorizer $authorizer,
        ?array $readOnlyMethods = null,
        ?callable $fence = null,
    ) {
        $this->readOnlyMethods = $readOnlyMethods ?? self::DEFAULT_READ_ONLY_METHODS;
        $this->fence = $fence;
    }

    /**
     * Authorize a single component action against the screen's admin path.
     *
     * The decision uses the v1 URI+method model (ADR-001 Layer-2): the screen path
     * is checked against the user's permission `http_uri` catalog. A read maps to
     * GET (same as menu visibility), a mutation to POST.
     *
     * @param AdminUserContract $user      Authenticated admin user.
     * @param string|null       $screenUri Admin path of the screen (no scheme/host).
     * @param string            $action    Method being invoked (e.g. "mount", "delete").
     * @return AuthorizationDecision Allow or deny, with a stable reason.
     */
    public function authorize(
        AdminUserContract $user,
        ?string $screenUri,
        string $action,
    ): AuthorizationDecision {
        $isMutating = !in_array($action, $this->readOnlyMethods, true);

        // Seam (ADR admin-shell_action-fence-seam): a registered fence may VETO before the
        // RBAC core decides. The veto is independent of roles, so it binds administrators
        // too; it can only add a "deny", never an "allow". Empty seam = no-op.
        $veto = $this->fenceVeto($user, $screenUri, $action);
        if ($veto !== null) {
            return AuthorizationDecision::deny($veto);
        }

        return $this->authorizer->authorize($user, $screenUri, $isMutating);
    }

    /**
     * Run the configured veto fence, if any.
     *
     * WHY fail-closed: a fence exists to narrow access; if it cannot answer (throws) the
     * safe outcome is to deny — never to fall through to the broader RBAC decision.
     *
     * @param AdminUserContract $user      Authenticated admin user.
     * @param string|null       $screenUri Admin path of the screen.
     * @param string            $action    Method being invoked.
     * @return string|null Deny reason when vetoed; null to let RBAC decide.
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-multi-store-pro-store-admin-fence
     * @aidlc-adr admin-shell_action-fence-seam
     */
    private function fenceVeto(AdminUserContract $user, ?string $screenUri, string $action): ?string
    {
        $fence = $this->fence ?? self::configuredFence();
        if ($fence === null) {
            return null;
        }

        try {
            $veto = $fence($user, $screenUri, $action);
        } catch (\Throwable $e) {
            if (function_exists('gp247_report')) {
                gp247_report($e);
            }

            return 'action_fence_error';
        }

        return (is_string($veto) && $veto !== '') ? $veto : null;
    }

    /**
     * The fence registered in config('gp247-config.admin.action_fence'), read at call time.
     *
     * WHY call-time + container-safe: plugins register the fence in their provider boot,
     * which may run after this singleton was built; and this class is unit-tested without
     * a Laravel container, where config() is unavailable — both cases must resolve to null.
     *
     * @return callable|null
     */
    private static function configuredFence(): ?callable
    {
        $app = \Illuminate\Container\Container::getInstance();
        if ($app === null || !$app->bound('config')) {
            return null;
        }

        $fence = $app->make('config')->get('gp247-config.admin.action_fence');

        return (!empty($fence) && is_callable($fence)) ? $fence : null;
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
