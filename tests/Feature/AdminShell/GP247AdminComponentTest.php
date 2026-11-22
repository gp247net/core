<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Domain\AuthorizationException;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\TestAdminComponent;
use GP247\Core\Tests\TestCase;
use GP247\Core\Tests\Unit\AdminShell\Support\FakeAdminUser;

/**
 * Integration tests exercising the two-layer authorization through the real
 * Livewire 4 lifecycle: mount (view) and a mutating action (delete).
 *
 * The current admin user is injected via the container binding, so these tests
 * validate the trait/base wiring without the GP247 database.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-LW-001
 * @aidlc-adr ADR-001
 */
class GP247AdminComponentTest extends TestCase
{
    /**
     * Bind the current admin user contract to an in-memory fake.
     *
     * @param bool     $administrator Whether the fake holds the administrator role.
     * @param bool     $viewAll       Whether the fake holds the view.all role.
     * @param string[] $slugs         Granted permission slugs.
     * @return void
     */
    private function asUser(bool $administrator = false, bool $viewAll = false, array $uris = []): void
    {
        $this->app->bind(
            AdminUserContract::class,
            static fn (): AdminUserContract => new FakeAdminUser($administrator, $viewAll, $uris),
        );
    }

    /**
     * Assert that running the action is denied by the authorization core.
     *
     * WHY: Livewire executes mount()/actions inside its render pipeline, which
     * wraps a thrown AuthorizationException (e.g. in ViewException). We unwrap
     * the exception chain so the assertion stays robust to that wrapping.
     *
     * @param callable $action The Livewire interaction expected to be denied.
     * @return void
     */
    private function assertDenied(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected authorization to deny the action.');
        } catch (\Throwable $e) {
            $current = $e;
            while ($current !== null && !$current instanceof AuthorizationException) {
                $current = $current->getPrevious();
            }
            $this->assertInstanceOf(
                AuthorizationException::class,
                $current,
                'Expected AuthorizationException in chain; got ' . $e::class . ': ' . $e->getMessage(),
            );
        }
    }

    public function test_mount_allowed_for_user_with_matching_slug(): void
    {
        $this->asUser(uris: ['gp247_admin/test']);

        Livewire::test(TestAdminComponent::class)->assertSee('admin-shell-test');
    }

    public function test_mount_denied_for_user_without_slug(): void
    {
        $this->asUser(uris: ['gp247_admin/other']);

        $this->assertDenied(fn () => Livewire::test(TestAdminComponent::class));
    }

    public function test_mount_allowed_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        Livewire::test(TestAdminComponent::class)->assertSee('admin-shell-test');
    }

    public function test_mutating_action_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        // Mount (read) is allowed; the delete (mutating) action must be blocked.
        $component = Livewire::test(TestAdminComponent::class);

        $this->assertDenied(fn () => $component->call('delete'));
    }

    public function test_mutating_action_allowed_with_slug(): void
    {
        $this->asUser(uris: ['gp247_admin/test']);

        Livewire::test(TestAdminComponent::class)
            ->call('delete')
            ->assertSet('deleted', true);
    }

    public function test_administrator_passes_everything(): void
    {
        $this->asUser(administrator: true);

        Livewire::test(TestAdminComponent::class)
            ->call('delete')
            ->assertSet('deleted', true);
    }
}
