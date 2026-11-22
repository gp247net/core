<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\PermissionManager;
use GP247\Core\Models\AdminPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * Feature tests for the two-panel permission manager (the single routed permission
 * screen after the TailAdmin cutover). Covers CRUD/list/authorization (ported from
 * the retired PermissionForm/PermissionList components) plus the route-picker scoping
 * fix (ADR-admin-shell-rbac-permission-route-picker-scoping): the seeded wildcard
 * permission `file.full = ANY::<prefix>/uploads/*` must be selectable, always-allow
 * util routes stay hidden, and individual LFM sub-routes are not offered.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005, ADR-007, ADR-admin-shell-rbac-permission-route-picker-scoping
 */
class PermissionManagerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * Admin prefix used to build expected route URIs.
     *
     * @return string
     */
    private function prefix(): string
    {
        return defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin';
    }

    /**
     * Flat list of the URIs the picker offers (as the blade/searchable-select sees them).
     *
     * @return string[]
     */
    private function pickerUris(): array
    {
        $this->asUser(slugs: ['admin_permission']);
        $instance = Livewire::test(PermissionManager::class)->instance();

        return collect($instance->getRouteGroupsProperty())
            ->flatten(1)
            ->pluck('uri')
            ->all();
    }

    // --- Route-picker scoping fix -------------------------------------------------

    public function test_route_picker_exposes_only_the_lfm_uploads_wildcard(): void
    {
        $uris        = $this->pickerUris();
        $uploadsUris = array_values(array_filter(
            $uris,
            fn (string $u): bool => str_contains($u, $this->prefix() . '/uploads'),
        ));

        // The only uploads option is the group wildcard (matches seeded `file.full`);
        // the ~15 individual LFM sub-routes (delete/rename/newfolder/...) are not offered.
        $this->assertSame(['ANY::' . $this->prefix() . '/uploads/*'], $uploadsUris);
    }

    public function test_route_picker_hides_always_allow_util_routes(): void
    {
        $uris = $this->pickerUris();

        foreach (['login', 'logout', 'forgot', 'deny', 'locale'] as $util) {
            foreach ($uris as $u) {
                $this->assertStringNotContainsString(
                    $this->prefix() . '/' . $util,
                    $u,
                    "Always-allow route '$util' must not appear in the picker.",
                );
            }
        }
    }

    public function test_editing_uploads_permission_keeps_uri_on_save(): void
    {
        $this->asUser(slugs: ['admin_permission']);
        $uri  = 'ANY::' . $this->prefix() . '/uploads/*';
        $perm = AdminPermission::create(['name' => 'ZZ Uploads', 'slug' => 'zz_uploads', 'http_uri' => $uri]);

        Livewire::test(PermissionManager::class, ['id' => $perm->id])
            ->assertSet('form.http_uri', [$uri])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($uri, $perm->fresh()->http_uri);
    }

    // --- CRUD (ported from PermissionFormTest) -----------------------------------

    public function test_creates_permission_with_joined_http_uri(): void
    {
        $this->asUser(slugs: ['admin_permission']);

        Livewire::test(PermissionManager::class)
            ->set('form.name', 'Test Perm')
            ->set('form.slug', 'test_perm')
            ->set('form.http_uri', ['ANY::' . $this->prefix() . '/admin-shell/languages/*', 'GET::' . $this->prefix() . '/admin-shell/permissions'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $perm = AdminPermission::where('slug', 'test_perm')->first();
        $this->assertNotNull($perm);
        $this->assertSame(
            'ANY::' . $this->prefix() . '/admin-shell/languages/*,GET::' . $this->prefix() . '/admin-shell/permissions',
            $perm->http_uri,
        );
    }

    public function test_validates_required_and_slug_format(): void
    {
        $this->asUser(slugs: ['admin_permission']);

        Livewire::test(PermissionManager::class)
            ->set('form.name', '')
            ->set('form.slug', 'has spaces!')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.slug']);
    }

    public function test_rejects_duplicate_slug(): void
    {
        $this->asUser(slugs: ['admin_permission']);
        AdminPermission::create(['name' => 'Existing', 'slug' => 'dup_slug', 'http_uri' => '']);

        Livewire::test(PermissionManager::class)
            ->set('form.name', 'Another')
            ->set('form.slug', 'dup_slug')
            ->call('save')
            ->assertHasErrors('form.slug');
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(PermissionManager::class)
            ->set('form.name', 'Test Perm')
            ->set('form.slug', 'test_perm');

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(AdminPermission::where('slug', 'test_perm')->exists());
    }

    // --- List + delete (ported from PermissionListTest) --------------------------

    public function test_lists_permissions_for_authorized_user(): void
    {
        $this->asUser(slugs: ['admin_permission']);
        $perm = AdminPermission::create(['name' => 'ZZ Listed', 'slug' => 'zz_listed', 'http_uri' => 'GET::' . $this->prefix() . '/x']);

        Livewire::test(PermissionManager::class)->assertSee($perm->name);
    }

    public function test_single_delete_removes_permission(): void
    {
        $this->asUser(slugs: ['admin_permission']);
        $perm = AdminPermission::create(['name' => 'ZZ Del', 'slug' => 'zz_del', 'http_uri' => 'GET::' . $this->prefix() . '/x']);

        Livewire::test(PermissionManager::class)->call('delete', $perm->id);

        $this->assertFalse(AdminPermission::where('id', $perm->id)->exists());
    }

    public function test_delete_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $perm = AdminPermission::create(['name' => 'ZZ Keep', 'slug' => 'zz_keep', 'http_uri' => 'GET::' . $this->prefix() . '/x']);

        $component = Livewire::test(PermissionManager::class);

        $this->assertDenied(fn () => $component->call('delete', $perm->id));
        $this->assertTrue(AdminPermission::where('id', $perm->id)->exists());
    }
}
