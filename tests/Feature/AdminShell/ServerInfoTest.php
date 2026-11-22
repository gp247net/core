<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\ServerInfo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the read-only server-info screen (ADR-001/005): renders PHP runtime,
 * extensions and packages for an authorized user; denied read for unmapped user.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002
 * @aidlc-adr ADR-001, ADR-005
 */
class ServerInfoTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    public function test_renders_php_runtime_and_extensions(): void
    {
        $this->asUser(slugs: ['admin_server_info']);

        Livewire::test(ServerInfo::class)
            ->assertSee(PHP_VERSION)
            ->assertSee('Extensions');
    }

    public function test_view_denied_without_permission(): void
    {
        $this->asUser(slugs: []);

        $this->assertDenied(fn () => Livewire::test(ServerInfo::class));
    }
}
