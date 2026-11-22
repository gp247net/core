<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Application\AdminRouteAccess;
use GP247\Core\AdminShell\Http\Livewire\SystemAlerts;
use GP247\Core\AdminShell\Support\AdminAlertRegistry;
use GP247\Core\Middleware\PermissionMiddleware;
use GP247\Core\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * The "System alerts" page: an Administrator sees every check grouped by level with
 * its technical detail, filters by level and checks again; nobody else can open the
 * page or see its menu item, whatever role permissions they hold.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts-page
 * @aidlc-adr admin-shell_system-alerts-page
 */
class SystemAlertsPageTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private const MENU_URI = 'admin::system_alerts';

    /** @var array<string, class-string> */
    private array $registered = [];

    /**
     * Start from an empty alert cache and remember the core registry.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registered = AdminAlertRegistry::all();
        Cache::flush();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        AdminAlertRegistry::reset();
        foreach ($this->registered as $class) {
            AdminAlertRegistry::register($class);
        }

        parent::tearDown();
    }

    public function test_an_administrator_sees_every_check_grouped_by_level_with_its_detail(): void
    {
        $this->asUser(administrator: true);
        config(['app.debug' => true]);

        Livewire::test(SystemAlerts::class)
            ->assertSeeHtml('data-testid="admin-alerts-page"')
            ->assertSeeHtml('data-testid="admin-alerts-item-app_debug"')
            ->assertSeeHtml('data-testid="admin-alerts-detail-app_debug"')
            ->assertSeeHtml('data-testid="admin-alerts-doc-app_debug"')
            ->assertSeeHtml('data-testid="admin-alerts-group-critical"');
    }

    public function test_the_page_has_check_again_four_filters_and_the_passed_checks(): void
    {
        $this->asUser(administrator: true);
        config(['app.debug' => false]);

        $component = Livewire::test(SystemAlerts::class)
            ->assertSeeHtml('data-testid="admin-alerts-recheck"')
            ->assertSeeHtml('data-testid="admin-alerts-passed-app_debug"');
        foreach (['all', 'critical', 'warning', 'ok'] as $filter) {
            $component->assertSeeHtml('data-testid="admin-alerts-filter-' . $filter . '"');
        }
    }

    public function test_a_fixed_condition_clears_after_check_again_on_the_page(): void
    {
        $this->asUser(administrator: true);
        config(['app.debug' => true]);
        $component = Livewire::test(SystemAlerts::class)
            ->assertSeeHtml('data-testid="admin-alerts-item-app_debug"');

        config(['app.debug' => false]);

        Livewire::test(SystemAlerts::class)
            ->assertSeeHtml('data-testid="admin-alerts-item-app_debug"');

        $component->call('recheck')
            ->assertDontSeeHtml('data-testid="admin-alerts-item-app_debug"')
            ->assertSeeHtml('data-testid="admin-alerts-passed-app_debug"');
    }

    /**
     * @return array<string, array{0: bool, 1: array<int, string>}>
     */
    public static function nonAdministrators(): array
    {
        return [
            'view-all' => [true, []],
            'store-admin' => [false, ['admin_order', 'admin_product']],
            'granted the page path' => [false, ['admin_system_alerts']],
        ];
    }

    /**
     * @param bool               $viewAll
     * @param array<int, string> $slugs
     * @return void
     */
    #[DataProvider('nonAdministrators')]
    public function test_a_non_administrator_cannot_open_the_page_component(bool $viewAll, array $slugs): void
    {
        $this->asUser(viewAll: $viewAll, slugs: $slugs);

        $this->assertDenied(fn () => Livewire::test(SystemAlerts::class));
    }

    public function test_check_again_on_the_page_is_refused_once_the_user_is_no_longer_an_administrator(): void
    {
        $this->asUser(administrator: true);
        $component = Livewire::test(SystemAlerts::class);

        $this->asUser(viewAll: true);

        $this->assertDenied(fn () => $component->call('recheck'));
    }

    public function test_the_page_path_is_administrator_only_in_the_shared_permission_list(): void
    {
        $this->assertContains($this->pagePath(), (new PermissionMiddleware())->administratorOnly());
    }

    public function test_route_access_refuses_the_page_to_view_all_and_never_consults_role_permissions(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.system_alerts');
        $this->assertNotNull($route, 'route admin.system_alerts must exist');

        $this->assertFalse(AdminRouteAccess::allows($this->user(viewAll: true), 'GET', $route, $this->pagePath()));
        // Role permissions would throw if consulted: the administrator-only rule must decide first.
        $this->assertFalse(AdminRouteAccess::allows($this->user(), 'GET', $route, $this->pagePath()));
        $this->assertTrue(AdminRouteAccess::allows($this->user(administrator: true), 'GET', $route, $this->pagePath()));
    }

    public function test_view_all_still_sees_other_screens_in_the_menu_but_not_the_alerts_page(): void
    {
        $viewAll = $this->user(viewAll: true);

        $this->assertFalse($viewAll->checkUrlAllowAccess(gp247_route_admin('admin.system_alerts')));
        $this->assertTrue($viewAll->checkUrlAllowAccess(gp247_route_admin('admin.server_info')));
        $this->assertFalse($this->user()->checkUrlAllowAccess(gp247_route_admin('admin.system_alerts')));
        $this->assertTrue($this->user(administrator: true)->checkUrlAllowAccess(gp247_route_admin('admin.system_alerts')));
    }

    public function test_the_permission_middleware_sends_view_all_away_from_the_page(): void
    {
        $this->actingAs($this->user(viewAll: true), 'admin');
        $request = Request::create('/' . $this->pagePath(), 'GET');
        $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));

        $response = (new PermissionMiddleware())->handle($request, fn () => response('page'));

        $this->assertNotSame('page', $response->getContent());
        $this->assertTrue($response->isRedirect());
    }

    public function test_the_header_shield_and_the_dashboard_block_link_to_the_page(): void
    {
        $header = file_get_contents(base_path('vendor/gp247/core/src/Views/admin/partials/header.blade.php'));
        $block = file_get_contents(base_path('vendor/gp247/core/src/Views/admin/livewire/admin-alerts.blade.php'));

        $this->assertMatchesRegularExpression("/gp247_route_admin\\('admin\\.system_alerts'\\)[^>]*data-testid=\"admin-header-alerts\"/s", $header);
        $this->assertStringContainsString("gp247_route_admin('admin.system_alerts')", $block);
    }

    public function test_the_upgrade_adds_the_menu_item_once_in_the_security_group(): void
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $menu = $db->table(GP247_DB_PREFIX . 'admin_menu');
        $menu->clone()->where('uri', self::MENU_URI)->delete();
        $migration = require base_path('vendor/gp247/core/src/Database/Migrations/upgrade/2026_11_04_145407_add_system_alerts_page.php');

        $migration->up();
        $migration->up();

        $rows = $menu->clone()->where('uri', self::MENU_URI)->get();
        $this->assertCount(1, $rows);
        $parent = $menu->clone()->where('key', 'ADMIN_SECURITY')->value('id');
        $this->assertSame((int) $parent, (int) $rows->first()->parent_id);
        $this->assertTrue($db->table(GP247_DB_PREFIX . 'languages')
            ->where('code', 'admin.menu_titles.system_alerts')->where('location', 'en')->exists());

        $migration->down();
        $this->assertFalse($menu->clone()->where('uri', self::MENU_URI)->exists());
    }

    /**
     * @return string Request path of the page, e.g. "gp247_admin/system_alerts".
     */
    private function pagePath(): string
    {
        return (defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin') . '/system_alerts';
    }

    /**
     * An unsaved admin user with a fixed role answer; consulting its role permissions throws,
     * so a test fails loudly if a role grant could override the administrator-only rule.
     *
     * @param bool $administrator
     * @param bool $viewAll
     * @return AdminUser
     */
    private function user(bool $administrator = false, bool $viewAll = false): AdminUser
    {
        $user = \Mockery::mock(AdminUser::class)->makePartial();
        $user->shouldReceive('isAdministrator')->andReturn($administrator);
        $user->shouldReceive('isViewAll')->andReturn($viewAll);
        $user->shouldReceive('roles')->andThrow(new \RuntimeException('role permissions must not be consulted'));

        return $user;
    }
}
