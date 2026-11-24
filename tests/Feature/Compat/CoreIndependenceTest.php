<?php

namespace GP247\Core\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Core\Tests\TestCase;

/**
 * Verifies that gp247/core can function as a standalone admin package without
 * requiring gp247/front or gp247/shop to be installed (US-CMP-006, MC-009).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-006
 * @aidlc-adr ADR (MC-009)
 */
class CoreIndependenceTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * core/composer.json must not list gp247/front or gp247/shop as a hard dependency.
     */
    public function test_core_composer_has_no_front_or_shop_dependency(): void
    {
        $composerPath = base_path('vendor/gp247/core/composer.json');
        $this->assertFileExists($composerPath, 'core/composer.json must exist');

        $composer = json_decode(file_get_contents($composerPath), true);
        $require  = $composer['require'] ?? [];

        $this->assertArrayNotHasKey(
            'gp247/front',
            $require,
            'gp247/core must not hard-require gp247/front (MC-009)'
        );
        $this->assertArrayNotHasKey(
            'gp247/shop',
            $require,
            'gp247/core must not hard-require gp247/shop (MC-009)'
        );
    }

    /**
     * gp247_store_get_list_domain_of_array_link() must return an empty Collection
     * (not throw) when gp247/front is absent — i.e., the FrontLink model does not
     * exist.
     *
     * Since the store 1-1 standardization a link's
     * owning store is resolved from its scalar store_id via the FrontLink entity;
     * the retired FrontLinkStore pivot is gone, so the guard now keys on FrontLink.
     * We verify the class_exists guard is present (core degrades gracefully when
     * front is absent) via a mock-free code inspection.
     */
    public function test_gp247_store_get_list_domain_of_array_link_has_class_exists_guard(): void
    {
        $source = file_get_contents(
            base_path('vendor/gp247/core/src/Library/Helpers/store.php')
        );

        $this->assertStringContainsString(
            'class_exists',
            $source,
            'store.php must contain a class_exists guard for the optional FrontLink model (US-CMP-006)'
        );
    }

    /**
     * Both link-domain helpers must guard on the optional FrontLink model so core
     * runs standalone (US-CMP-006). Under 1-1 ownership the guarded class is the
     * FrontLink entity (the store_id owner), not the retired FrontLinkStore pivot.
     */
    public function test_gp247_store_get_list_domain_of_link_detail_has_class_exists_guard(): void
    {
        $source = file_get_contents(
            base_path('vendor/gp247/core/src/Library/Helpers/store.php')
        );

        // Verify the guard appears at least twice (once per function).
        $count = substr_count($source, 'class_exists(\\GP247\\Front\\Models\\FrontLink::class');
        $this->assertGreaterThanOrEqual(
            2,
            $count,
            'Both store.php link-domain helpers must guard on FrontLink::class (US-CMP-006, 1-1 ownership)'
        );
    }

    /**
     * When called with an empty array, the helper must return an empty Collection/array (not throw).
     */
    public function test_gp247_store_get_list_domain_of_array_link_with_empty_input(): void
    {
        $result = gp247_store_get_list_domain_of_array_link([]);
        $this->assertTrue(
            is_array($result) || $result instanceof \Traversable,
            'gp247_store_get_list_domain_of_array_link([]) must return iterable'
        );
    }

    /**
     * When called with a non-existent link ID, the detail helper must return an array (not throw).
     */
    public function test_gp247_store_get_list_domain_of_link_detail_returns_array(): void
    {
        $result = gp247_store_get_list_domain_of_link_detail('non-existent-id-99999');
        $this->assertIsArray($result);
    }

    /**
     * Core admin shell routes (login, dashboard) must be registered without front/shop.
     */
    public function test_core_admin_routes_are_registered(): void
    {
        $adminPrefix = defined('GP247_ADMIN_PREFIX') ? '/' . GP247_ADMIN_PREFIX : '/gp247_admin';

        // WHY: core login route is nested under /auth/ sub-group (LoginController).
        $loginRoute = $adminPrefix . '/auth/login';
        $response   = $this->get($loginRoute);

        // 200 = login page accessible; 302 = redirect (already logged in); both are fine
        // 404 = route not registered = FAIL
        $this->assertNotEquals(
            404,
            $response->status(),
            "Core admin login route {$loginRoute} must be registered (US-CMP-006)"
        );
    }

    /**
     * Admin-shell wiring (bindings + routes) must be discoverable without
     * front/shop being installed. It now ships inside CoreServiceProvider
     * itself (the former, separate AdminShellServiceProvider was merged
     * into it).
     */
    public function test_admin_shell_wiring_is_registered_via_core_provider(): void
    {
        $providers = app()->getLoadedProviders();

        $this->assertArrayHasKey(
            \GP247\Core\CoreServiceProvider::class,
            $providers,
            'CoreServiceProvider must be loaded (US-CMP-006)'
        );

        $this->assertTrue(
            app()->bound(\GP247\Core\AdminShell\Domain\AdminUserContract::class),
            'Admin-shell AdminUserContract binding must be registered by CoreServiceProvider (US-CMP-006)'
        );
    }

    /**
     * laravel/legacy-factories is abandoned (Composer warns on every install or
     * update of a site) and nothing in core uses it, so core must not require it.
     * Site code that still needs the Laravel 7 style factories can require the
     * package itself.
     */
    public function test_core_composer_does_not_require_abandoned_legacy_factories(): void
    {
        $composer = json_decode(file_get_contents(base_path('vendor/gp247/core/composer.json')), true);

        $this->assertArrayNotHasKey(
            'laravel/legacy-factories',
            $composer['require'] ?? [],
            'gp247/core must not require the abandoned laravel/legacy-factories (US-CMP-006)'
        );
    }

    /**
     * Core code must not depend on the Laravel 7 style factory API that
     * laravel/legacy-factories provides: the global factory() helper,
     * $factory->define()/state()/after*() and the Illuminate\Database\Eloquent\Factory
     * (or FactoryBuilder) classes. Class-based factories
     * (Illuminate\Database\Eloquent\Factories\Factory) are fine.
     */
    public function test_core_source_does_not_use_legacy_factory_api(): void
    {
        $patterns = [
            'global factory() helper' => '/(?<![\w$>:])factory\s*\(/',
            '$factory->define/state/after*' => '/\$factory\s*->\s*(define|state|afterMaking|afterCreating)\b/',
            'legacy Factory / FactoryBuilder class' => '/Illuminate\\\\Database\\\\Eloquent\\\\(Factory|FactoryBuilder)\b/',
        ];

        // WHY: preg_match() returns false on a broken pattern; without this the
        // scan below would silently find nothing and the guard would always pass.
        foreach ($patterns as $label => $regex) {
            $this->assertNotFalse(preg_match($regex, ''), "Invalid guard pattern: {$label}");
        }

        $offenders = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('vendor/gp247/core/src'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            foreach ($patterns as $label => $regex) {
                if (preg_match($regex, $source)) {
                    $offenders[] = str_replace('\\', '/', $file->getPathname()) . " ({$label})";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'gp247/core must not use the legacy factory API (US-CMP-006)'
        );
    }
}
