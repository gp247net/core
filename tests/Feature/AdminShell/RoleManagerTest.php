<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\RoleManager;
use GP247\Core\Models\AdminPermission;
use GP247\Core\Models\AdminRole;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * Feature tests for the two-panel role manager (the single routed role screen after
 * the TailAdmin cutover). Ported from the retired RoleForm/RoleList components:
 * authorization, validation, permission pivot sync, listing, delete, and the
 * built-in role guard (GP247_GUARD_ROLES), against the live schema (rolled back).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005, ADR-007
 */
class RoleManagerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    public function test_creates_role_and_syncs_permissions(): void
    {
        $this->asUser(slugs: ['admin_role']);
        $perm = AdminPermission::create(['name' => 'ZZ Perm', 'slug' => 'zz_perm', 'http_uri' => '']);

        Livewire::test(RoleManager::class)
            ->set('form.name', 'ZZ Role')
            ->set('form.slug', 'zz_role')
            ->set('form.permissions', [(string) $perm->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $role = AdminRole::where('slug', 'zz_role')->first();
        $this->assertNotNull($role);
        $this->assertTrue($role->permissions->contains('id', $perm->id));
    }

    public function test_validates_required_and_slug_format(): void
    {
        $this->asUser(slugs: ['admin_role']);

        Livewire::test(RoleManager::class)
            ->set('form.name', '')
            ->set('form.slug', 'bad slug')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.slug']);
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(RoleManager::class)
            ->set('form.name', 'ZZ Role')
            ->set('form.slug', 'zz_role');

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(AdminRole::where('slug', 'zz_role')->exists());
    }

    public function test_lists_roles_for_authorized_user(): void
    {
        $this->asUser(slugs: ['admin_role']);
        $role = AdminRole::create(['name' => 'ZZ Listed', 'slug' => 'zz_listed']);

        Livewire::test(RoleManager::class)->assertSee($role->name);
    }

    public function test_single_delete_removes_role(): void
    {
        $this->asUser(slugs: ['admin_role']);
        $role = AdminRole::create(['name' => 'ZZ Del', 'slug' => 'zz_del']);

        Livewire::test(RoleManager::class)->call('delete', $role->id);

        $this->assertFalse(AdminRole::where('id', $role->id)->exists());
    }

    public function test_single_delete_keeps_guarded_role(): void
    {
        $this->asUser(slugs: ['admin_role']);

        // GP247_GUARD_ROLES = [1, 2] (administrator / view.all): deleting is a no-op.
        Livewire::test(RoleManager::class)->call('delete', 1);

        $this->assertTrue(AdminRole::where('id', 1)->exists());
    }

    public function test_delete_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $role = AdminRole::create(['name' => 'ZZ Keep', 'slug' => 'zz_keep']);

        $component = Livewire::test(RoleManager::class);

        $this->assertDenied(fn () => $component->call('delete', $role->id));
        $this->assertTrue(AdminRole::where('id', $role->id)->exists());
    }
}
