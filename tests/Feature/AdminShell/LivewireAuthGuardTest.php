<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Application\AuthorizeAdminAction;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Infrastructure\LivewireAuthGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use GP247\Core\Tests\TestCase;
use GP247\Core\Tests\Unit\AdminShell\Support\FakeAdminUser;

/**
 * Integration tests for the Layer-1 global guard over the shared Livewire update
 * endpoint. Under the URI+method model (ADR-001, Hướng B) the real global gate is
 * the persistent PermissionMiddleware; this guard is a dormant, opt-in fallback
 * that cannot see the origin screen path in the batched payload, so it authorizes
 * with a null screen URI: administrator passes, view.all may read but not mutate,
 * and every other user is denied by default (never a false-allow).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
class LivewireAuthGuardTest extends TestCase
{
    /**
     * Build a Livewire-style update request carrying one component call.
     *
     * @param string $component Component name placed in the snapshot memo.
     * @param string $method    Invoked method name.
     * @return Request The crafted request.
     */
    private function updateRequest(string $component, string $method): Request
    {
        $request = Request::create('/livewire/update', 'POST');
        $request->merge([
            'components' => [[
                'snapshot' => ['memo' => ['name' => $component]],
                'calls' => [['method' => $method]],
            ]],
        ]);

        return $request;
    }

    private function guard(): LivewireAuthGuard
    {
        return new LivewireAuthGuard($this->app->make(AuthorizeAdminAction::class));
    }

    public function test_administrator_passes_guard(): void
    {
        $this->app->bind(AdminUserContract::class, static fn () => new FakeAdminUser(administrator: true));

        $response = $this->guard()->handle(
            $this->updateRequest('gp247-core::product-list', 'delete'),
            static fn () => response('ok'),
        );

        $this->assertSame('ok', $response->getContent());
    }

    public function test_regular_user_denied_by_default(): void
    {
        $this->app->bind(AdminUserContract::class, static fn () => new FakeAdminUser(uris: ['gp247_admin/product']));

        $this->expectException(HttpException::class);

        $this->guard()->handle(
            $this->updateRequest('gp247-core::product-list', 'delete'),
            static fn () => response('ok'),
        );
    }

    public function test_view_all_read_action_allowed(): void
    {
        $this->app->bind(AdminUserContract::class, static fn () => new FakeAdminUser(viewAll: true));

        $response = $this->guard()->handle(
            $this->updateRequest('gp247-core::product-list', 'setSort'),
            static fn () => response('ok'),
        );

        $this->assertSame('ok', $response->getContent());
    }

    public function test_view_all_mutating_action_denied(): void
    {
        $this->app->bind(AdminUserContract::class, static fn () => new FakeAdminUser(viewAll: true));

        $this->expectException(HttpException::class);

        $this->guard()->handle(
            $this->updateRequest('gp247-core::product-list', 'delete'),
            static fn () => response('ok'),
        );
    }
}
