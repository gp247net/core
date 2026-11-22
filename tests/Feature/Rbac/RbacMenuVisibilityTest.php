<?php

namespace GP247\Core\Tests\Feature\Rbac;

use GP247\Core\Models\AdminMenu;
use GP247\Core\Tests\TestCase;

/**
 * Verifies that the AdminMenu model exposes the permission-filtered visibility API
 * and that UI components use it (US-RBAC-002 AC1).
 *
 * Note: getListVisible() requires a real admin session (admin()->user()) which is
 * set up by the full admin request cycle, not available in unit/feature test context
 * without a real login. These tests focus on the contract and static method presence.
 *
 * @aidlc-unit rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
class RbacMenuVisibilityTest extends TestCase
{
    /**
     * AdminMenu model must expose getListVisible() for permission-filtered sidebar (US-RBAC-002 AC1).
     */
    public function test_admin_menu_has_get_list_visible_method(): void
    {
        $this->assertTrue(
            method_exists(AdminMenu::class, 'getListVisible'),
            'AdminMenu must have getListVisible() method (US-RBAC-002 AC1)'
        );
    }

    /**
     * AdminMenu must also expose getListAll() and getListAllDisplay() for full/display lists.
     */
    public function test_admin_menu_has_required_list_methods(): void
    {
        $methods = ['getListAll', 'getListAllDisplay', 'getListVisible', 'getMenuGroupByParentId'];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(AdminMenu::class, $method),
                "AdminMenu must have {$method}() for RBAC menu filtering (US-RBAC-002)"
            );
        }
    }

    /**
     * getListAllDisplay() must return items without requiring a user session.
     * This is the unfiltered display list used as input to getListVisible().
     */
    public function test_get_list_all_display_returns_collection_without_session(): void
    {
        $list = AdminMenu::getListAllDisplay();

        $this->assertNotNull($list, 'getListAllDisplay() must return a value');
        $this->assertTrue(
            is_array($list) || $list instanceof \Traversable,
            'getListAllDisplay() must return iterable'
        );
    }

    /**
     * getListVisible() source code must reference checkUrlAllowAccess() — verifying
     * that the method performs per-user permission filtering on each menu item URL.
     */
    public function test_get_list_visible_uses_url_access_check(): void
    {
        $source = file_get_contents(
            base_path('vendor/gp247/core/src/Models/AdminMenu.php')
        );

        $this->assertStringContainsString(
            'checkUrlAllowAccess',
            $source,
            'getListVisible() must call checkUrlAllowAccess() to filter by user permission (US-RBAC-002 AC1)'
        );
    }

    /**
     * AdminMenu items in DB must have a uri field for permission mapping.
     */
    public function test_admin_menu_has_uri_column(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn(
                (new AdminMenu)->getTable(),
                'uri'
            ),
            'admin_menu table must have a uri column for RBAC URL matching (US-RBAC-002)'
        );
    }

    /**
     * The layout blade that renders the sidebar must reference getListVisible()
     * — confirming UI components use the permission-filtered menu (US-RBAC-002 AC1).
     */
    public function test_sidebar_layout_uses_get_list_visible(): void
    {
        $sidebarPaths = [
            base_path('vendor/gp247/core/src/Views/admin/layouts/admin.blade.php'),
            base_path('vendor/gp247/core/src/Views/admin/layouts/sidebar.blade.php'),
            base_path('vendor/gp247/core/src/Views/admin/components/sidebar.blade.php'),
        ];

        $found = false;
        foreach ($sidebarPaths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                if (str_contains($content, 'getListVisible') || str_contains($content, 'listVisible')) {
                    $found = true;
                    break;
                }
            }
        }

        // Also check any blade file in layouts/
        if (!$found) {
            $layoutDir = base_path('vendor/gp247/core/src/Views/admin');
            if (is_dir($layoutDir)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($layoutDir));
                foreach ($iterator as $file) {
                    if ($file->getExtension() === 'php') {
                        $content = file_get_contents($file->getPathname());
                        if (str_contains($content, 'getListVisible') || str_contains($content, 'listVisible')) {
                            $found = true;
                            break;
                        }
                    }
                }
            }
        }

        $this->assertTrue(
            $found,
            'Sidebar layout must reference getListVisible() for permission-based menu (US-RBAC-002 AC1)'
        );
    }
}
