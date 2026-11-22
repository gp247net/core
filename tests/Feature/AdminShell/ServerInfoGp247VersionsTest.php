<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use Composer\InstalledVersions;
use GP247\Core\AdminShell\Http\Livewire\ServerInfo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * Feature-Test: the Server info screen opens with a "GP247" block listing the
 * GP247 package versions exactly as the release tags print them, so a site owner
 * without CLI access can compare against the release notes. Access and the set of
 * information shown stay unchanged (no environment secrets).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-server-info-gp247-versions
 * @aidlc-adr admin-shell_gp247-version-display
 */
class ServerInfoGp247VersionsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /** Core packages of S-Cart, in the fixed order the block must show them. */
    private const CORE_ORDER = ['gp247/core', 'gp247/front', 'gp247/shop', 'gp247/mcp'];

    /**
     * Render the screen as a user holding the server-info permission.
     *
     * @return string Rendered HTML.
     */
    private function renderScreen(): string
    {
        $this->asUser(slugs: ['admin_server_info']);

        return Livewire::test(ServerInfo::class)->html();
    }

    /**
     * Rows of the GP247 block as package => version text, in display order.
     *
     * @param string $html Rendered HTML.
     * @return array<string, string>
     */
    private function gp247Rows(string $html): array
    {
        preg_match_all(
            '/data-testid="admin-shell-server-info-gp247-row"\s+data-package="([^"]+)".*?data-testid="admin-shell-server-info-gp247-version"[^>]*>\s*([^<]*?)\s*</s',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $rows = [];
        foreach ($matches as $match) {
            $rows[html_entity_decode($match[1])] = html_entity_decode($match[2]);
        }

        return $rows;
    }

    /**
     * Installed gp247/* packages other than the project root package.
     *
     * @return string[]
     */
    private function installedGp247Packages(): array
    {
        $root = InstalledVersions::getRootPackage()['name'] ?? null;

        return array_values(array_filter(
            InstalledVersions::getInstalledPackages(),
            fn (string $name) => str_starts_with($name, 'gp247/') && $name !== $root
        ));
    }

    public function test_gp247_block_is_first_and_lists_core_front_shop_mcp_in_order(): void
    {
        $html = $this->renderScreen();

        $blockAt = strpos($html, 'data-testid="admin-shell-server-info-gp247"');
        $this->assertNotFalse($blockAt, 'The GP247 block is missing.');
        $this->assertLessThan(strpos($html, PHP_VERSION), $blockAt, 'The GP247 block must come before the PHP block.');

        $installed = $this->installedGp247Packages();
        $expectedCore = array_values(array_intersect(self::CORE_ORDER, $installed));
        $others = array_values(array_diff($installed, self::CORE_ORDER));
        sort($others);

        $this->assertSame(array_merge($expectedCore, $others), array_keys($this->gp247Rows($html)));
    }

    public function test_versions_are_shown_exactly_as_the_release_tag(): void
    {
        $html = $this->renderScreen();
        $rows = $this->gp247Rows($html);

        $this->assertNotEmpty($rows);
        foreach ($rows as $package => $version) {
            $pretty = InstalledVersions::getPrettyVersion($package);
            if (str_starts_with((string) $pretty, 'dev-')) {
                $this->assertStringStartsWith($pretty, $version);
                continue;
            }
            $this->assertSame($pretty, $version, "Version of {$package} must match the release tag.");
        }

        $normalized = InstalledVersions::getVersion('gp247/core');
        if ($normalized !== InstalledVersions::getPrettyVersion('gp247/core')) {
            $this->assertStringNotContainsString('>' . $normalized . '<', $html, 'The normalized Composer version must not be shown.');
        }
    }

    public function test_project_root_package_is_not_in_the_gp247_block(): void
    {
        $root = InstalledVersions::getRootPackage()['name'] ?? '';

        $this->assertArrayNotHasKey($root, $this->gp247Rows($this->renderScreen()));
    }

    public function test_package_list_below_skips_packages_already_in_the_gp247_block(): void
    {
        $html = $this->renderScreen();
        $rows = $this->gp247Rows($html);
        $listAt = strpos($html, 'fa-box');
        $this->assertNotFalse($listAt, 'The package list card is missing.');
        $below = substr($html, $listAt);

        $this->assertStringContainsString('laravel/framework', $below);
        $this->assertStringContainsString(e(InstalledVersions::getPrettyVersion('laravel/framework')), $below);
        foreach (array_keys($rows) as $package) {
            $this->assertStringNotContainsString('>' . $package . '<', $below, "{$package} is already in the GP247 block.");
        }

        $root = InstalledVersions::getRootPackage()['name'] ?? '';
        if ($root !== '' && InstalledVersions::isInstalled($root)) {
            $this->assertStringContainsString('>' . $root . '<', $below, 'The project root package stays in the list below.');
        }
    }

    public function test_screen_never_prints_environment_secrets(): void
    {
        $html = $this->renderScreen();

        $secrets = array_filter([
            config('app.key'),
            config('database.connections.' . config('database.default') . '.password'),
            base_path(),
        ], fn ($value) => is_string($value) && strlen($value) >= 4);

        $this->assertNotEmpty($secrets);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $html);
            $this->assertStringNotContainsString(e($secret), $html);
        }
    }

    public function test_access_is_unchanged_view_denied_without_permission(): void
    {
        $this->asUser(slugs: []);

        $this->assertDenied(fn () => Livewire::test(ServerInfo::class));
    }

    public function test_labels_reach_upgraded_sites_through_gp247_update(): void
    {
        $migration = require base_path(
            'vendor/gp247/core/src/Database/Migrations/upgrade/2026_11_12_215502_add_server_info_gp247_language.php'
        );
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages');
        $table->whereIn('code', ['admin.server_info_gp247_title', 'admin.server_info_gp247_hint'])->delete();

        $migration->up();
        $migration->up();

        foreach (['admin.server_info_gp247_title', 'admin.server_info_gp247_hint'] as $code) {
            $locations = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')
                ->where('code', $code)->orderBy('location')->pluck('location')->all();
            $this->assertSame(['en', 'ja', 'vi'], $locations, "Label {$code} must exist once per locale.");
        }
    }
}
