<?php

namespace GP247\Core\AdminShell\Infrastructure;

use GP247\Core\AdminShell\Application\AuthorizeAdminAction;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Layer-1 global guard for the shared Livewire update endpoint (ADR-001).
 *
 * Livewire routes every interaction through POST /livewire/update, so the
 * route-level RBAC middleware cannot tell admin actions apart. This guard
 * inspects the batched payload, extracts each (component, method) pair, and runs
 * the shared authorization use case — denying the whole request if any call is
 * not permitted. It closes the endpoint gap that route-level RBAC leaves open
 * (RISK-TECH-001), with the component trait providing a second, in-component check.
 *
 * NOTE: the exact payload shape is finalized against the installed Livewire 4
 * runtime (see code_generation test_results — integration step). Parsing here is
 * defensive: unknown shapes fall through to the use case as an unresolved
 * component, which denies by default.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
final class LivewireAuthGuard
{
    /**
     * @param AuthorizeAdminAction $useCase Shared authorization use case.
     */
    public function __construct(private AuthorizeAdminAction $useCase)
    {
    }

    /**
     * Handle an incoming Livewire update request.
     *
     * @param Request $request The incoming HTTP request.
     * @param Closure $next    The next pipeline stage.
     * @return Response The downstream response, or a 403 when denied.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Authenticated admin is guaranteed by the upstream auth:admin middleware;
        // the contract is bound in AdminShellServiceProvider (swappable in tests).
        $user = app(AdminUserContract::class);

        foreach ($this->extractCalls($request) as $call) {
            $decision = $this->useCase->authorize(
                $user,
                $call['component'],
                $call['method'],
                $call['permission'] ?? null,
            );

            if (!$decision->isAllowed()) {
                abort(Response::HTTP_FORBIDDEN, $decision->reason());
            }
        }

        return $next($request);
    }

    /**
     * Extract (component, method) pairs from the Livewire update payload.
     *
     * @param Request $request The incoming request.
     * @return array<int,array{component:string,method:string,permission?:string}>
     */
    private function extractCalls(Request $request): array
    {
        $components = (array) $request->input('components', []);
        $calls = [];

        foreach ($components as $component) {
            $name = data_get($component, 'snapshot.memo.name')
                ?? data_get($component, 'snapshot.name', '');

            foreach ((array) data_get($component, 'calls', []) as $call) {
                $method = (string) data_get($call, 'method', '');
                if ($method === '') {
                    continue;
                }
                $calls[] = ['component' => (string) $name, 'method' => $method];
            }
        }

        return $calls;
    }
}
