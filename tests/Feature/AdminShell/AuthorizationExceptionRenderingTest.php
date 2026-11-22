<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use GP247\Core\Tests\TestCase;

/**
 * Verifies CoreServiceProvider's registerAuthorizationExceptionRendering()
 * handler (US-UI-008, merged from the former AdminShellServiceProvider):
 * a denied AuthorizationException must never
 * surface the framework's raw error
 * page. Livewire's "livewire/update" AJAX calls (X-Livewire header) get a
 * tagged JSON 403 that `admin.js`'s request hook turns into a toast; a plain
 * full-page GET gets the branded access-denied Blade screen instead.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-008
 * @aidlc-adr ADR-001, ADR-005
 */
class AuthorizationExceptionRenderingTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Register a throwaway route that always denies, so the test exercises the
     * real registered exception handler through the full HTTP kernel.
     *
     * @return void
     */
    private function registerDenyingRoute(): void
    {
        Route::get('/__test-authorization-exception', function () {
            throw AuthorizationException::fromReason('view_all_cannot_mutate');
        });
    }

    public function test_livewire_request_gets_tagged_json_403(): void
    {
        $this->registerDenyingRoute();

        $response = $this->withHeaders(['X-Livewire' => '1'])
            ->get('/__test-authorization-exception');

        $response->assertStatus(403);
        $response->assertJson(['gp247_admin_denied' => true]);
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_full_page_request_gets_branded_screen_not_raw_error(): void
    {
        $this->registerDenyingRoute();

        // The "plain" layout's header/sidebar render the current admin user, so
        // the branded screen needs a real authenticated session (unlike the
        // Livewire-JSON branch above, which never touches that layout).
        $user = AdminUser::createUser([
            'name' => 'ZZ Denied Screen', 'username' => 'zzdeniedscreen',
            'email' => 'zzdeniedscreen@example.com', 'password' => bcrypt('Password123!'), 'status' => 1,
        ]);
        $this->actingAs($user, 'admin');

        $response = $this->get('/__test-authorization-exception');

        $response->assertStatus(403);
        $response->assertDontSee('AuthorizationException', false);
        $response->assertViewIs('gp247-admin::errors.access-denied');
    }
}
