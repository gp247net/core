<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the account-settings screen (US-AUI-008 gap): the legacy
 * LoginController@getSetting view, ported to the TailAdmin plain layout. The
 * form still posts to the legacy admin.post_setting endpoint (presentation
 * only). Verifies an authenticated admin sees the ported form.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-008
 * @aidlc-adr ADR-002
 */
class AccountSettingScreenTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Create + authenticate an active admin on the `admin` guard.
     *
     * @return AdminUser
     */
    private function loginAdmin(): AdminUser
    {
        $user = AdminUser::create([
            'id' => 'AU-SETTING',
            'username' => 'setting_user',
            'password' => bcrypt('secret'),
            'name' => 'Setting User',
            'email' => 'setting@test.local',
            'status' => 1,
        ]);
        $this->actingAs($user, 'admin');

        return $user;
    }

    public function test_authenticated_admin_sees_ported_setting_form(): void
    {
        $this->loginAdmin();

        $response = $this->get(route('admin.setting'));

        $response->assertOk();
        // Proves the screen renders on the AdminShell (TailAdmin) plain layout,
        // not the legacy AdminLTE master.
        $response->assertSee('GP247/Core/AdminShell/css/admin.css', false);
        $response->assertSee(route('admin.post_setting'), false);
        $response->assertSee('name="name"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('name="_token"', false);
    }
}
