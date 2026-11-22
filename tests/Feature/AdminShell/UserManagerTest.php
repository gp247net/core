<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\UserManager;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the two-panel user manager (ADR-001/005/007): add/edit form left +
 * live list right, against the live schema (rolled back).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005, ADR-007
 */
class UserManagerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private const PASSWORD = 'Password123!';

    /**
     * Regression for a bug where UserManager::save() cleared form.password
     * before parent::save() ran validation, so the "required" rule always
     * failed even when a password was supplied.
     */
    public function test_creates_user_with_hashed_password_and_roles(): void
    {
        $this->asUser(slugs: ['admin_user']);
        $role = AdminRole::create(['name' => 'ZZ Role U', 'slug' => 'zz_role_u']);

        Livewire::test(UserManager::class)
            ->set('form.name', 'ZZ User')
            ->set('form.username', 'zzuser')
            ->set('form.email', 'zzuser@example.com')
            ->set('form.password', self::PASSWORD)
            ->set('form.roles', [(string) $role->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $user = AdminUser::where('username', 'zzuser')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertTrue($user->roles->contains('id', $role->id));
    }

    public function test_validates_required_and_username_format(): void
    {
        $this->asUser(slugs: ['admin_user']);

        Livewire::test(UserManager::class)
            ->set('form.name', '')
            ->set('form.username', 'bad name')
            ->set('form.email', 'not-an-email')
            ->set('form.password', '')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.username', 'form.email', 'form.password']);
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(UserManager::class)
            ->set('form.name', 'ZZ User')
            ->set('form.username', 'zzuser')
            ->set('form.email', 'zzuser@example.com')
            ->set('form.password', self::PASSWORD);

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(AdminUser::where('username', 'zzuser')->exists());
    }
}
