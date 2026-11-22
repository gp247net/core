<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\Tests\TestCase;

/**
 * PA-1 cutover (final): the interim admin-shell/* auth URLs are gone and the
 * modern TailAdmin login/forgot/reset screens are now served in-place at the
 * legacy entry points (admin.login/forgot/password_reset). Backend POST handlers
 * are untouched — the strangler is complete. Per-screen form rendering is covered
 * by AuthScreensTest; this guards the cutover invariant.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-009
 * @aidlc-adr ADR-002, ADR-006
 */
class AuthCutoverTest extends TestCase
{
    public function test_interim_admin_shell_auth_routes_are_removed(): void
    {
        $router = app('router');

        $this->assertFalse($router->has('gp247.admin-shell.login'));
        $this->assertFalse($router->has('gp247.admin-shell.forgot'));
        $this->assertFalse($router->has('gp247.admin-shell.password_reset'));
    }

    public function test_legacy_login_serves_modern_screen_in_place(): void
    {
        $this->get(route('admin.login'))
            ->assertOk()
            // The TailAdmin auth layout stylesheet proves the modern screen renders
            // at the legacy URL (not a redirect, not the AdminLTE view).
            ->assertSee('GP247/Core/AdminShell/css/admin.css', false)
            ->assertSee(route('admin.post_login'), false);
    }
}
