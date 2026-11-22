<?php

namespace GP247\Core\Tests\Unit\AdminShell;

use GP247\Core\Support\Gp247PackageVersions;
use PHPUnit\Framework\TestCase;

/**
 * Ordering and formatting rules of the GP247 package version list.
 *
 * @aidlc-unit admin-shell
 */
class Gp247PackageVersionsTest extends TestCase
{
    public function test_core_packages_come_first_in_fixed_order_then_others_alphabetically(): void
    {
        $installed = ['laravel/framework', 'gp247/zeta', 'gp247/shop', 'gp247/alpha', 'gp247/core', 'gp247/front', 'gp247/mcp'];

        $this->assertSame(
            ['gp247/core', 'gp247/front', 'gp247/shop', 'gp247/mcp', 'gp247/alpha', 'gp247/zeta'],
            Gp247PackageVersions::orderGp247($installed, null)
        );
    }

    public function test_missing_core_package_is_skipped_and_root_package_excluded(): void
    {
        $installed = ['gp247/s-cart', 'gp247/front', 'gp247/core', 'gp247/core'];

        $this->assertSame(['gp247/core', 'gp247/front'], Gp247PackageVersions::orderGp247($installed, 'gp247/s-cart'));
    }

    public function test_tagged_version_is_shown_as_is(): void
    {
        $this->assertSame('3.11.2', Gp247PackageVersions::format('3.11.2', '43ba4a5921043f9cd47cd4ea118970198037de5e'));
        $this->assertSame('v13.1.0', Gp247PackageVersions::format('v13.1.0', null));
    }

    public function test_branch_install_carries_a_short_commit_hash(): void
    {
        $this->assertSame('dev-master (43ba4a5)', Gp247PackageVersions::format('dev-master', '43ba4a5921043f9cd47cd4ea118970198037de5e'));
        $this->assertSame('dev-master', Gp247PackageVersions::format('dev-master', null));
    }

    public function test_unknown_version_shows_a_dash(): void
    {
        $this->assertSame('—', Gp247PackageVersions::format(null, null));
        $this->assertSame('—', Gp247PackageVersions::format('', 'abc'));
    }
}
