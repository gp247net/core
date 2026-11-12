<?php

namespace GP247\Core\Support;

use Composer\InstalledVersions;

/**
 * Display versions of installed Composer packages exactly as their release tags
 * print them (e.g. "3.11.2", not Composer's normalized "3.11.2.0"), so the Server
 * info screen and `gp247:info` show the same string the release notes use.
 *
 * Display only: version comparisons keep using the normalized versions returned by
 * gp247_composer_get_package_installed().
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-server-info-gp247-versions, US-CLI-004
 * @aidlc-adr admin-shell_gp247-version-display
 */
class Gp247PackageVersions
{
    /** Core packages of S-Cart, shown first and in this order. */
    public const CORE = ['gp247/core', 'gp247/front', 'gp247/shop', 'gp247/mcp'];

    /** Number of commit-hash characters appended to a branch install (dev-*). */
    private const REFERENCE_LENGTH = 7;

    /**
     * Installed gp247/* packages (core, front, shop, mcp first, then the others A→Z),
     * excluding the project root package whose version is not reliable after updates.
     *
     * @return array<string, string> package => display version
     */
    public static function gp247(): array
    {
        $versions = [];
        foreach (self::orderGp247(InstalledVersions::getInstalledPackages(), self::rootName()) as $package) {
            $versions[$package] = self::display($package);
        }

        return $versions;
    }

    /**
     * Display versions of the four core packages, null when a package is not installed.
     *
     * @return array<string, string|null> package => display version
     */
    public static function core(): array
    {
        $versions = [];
        foreach (self::CORE as $package) {
            $versions[$package] = InstalledVersions::isInstalled($package) ? self::display($package) : null;
        }

        return $versions;
    }

    /**
     * Every installed package A→Z with its display version.
     *
     * @return array<string, string> package => display version
     */
    public static function all(): array
    {
        $packages = array_unique(InstalledVersions::getInstalledPackages());
        sort($packages);

        $versions = [];
        foreach ($packages as $package) {
            $versions[$package] = self::display($package);
        }

        return $versions;
    }

    /**
     * Order gp247/* names: core packages in fixed order, then the others A→Z.
     *
     * @param string[]    $installed Installed package names.
     * @param string|null $root      Project root package name to exclude.
     * @return string[]
     */
    public static function orderGp247(array $installed, ?string $root): array
    {
        $gp247 = array_values(array_unique(array_filter(
            $installed,
            fn (string $name) => str_starts_with($name, 'gp247/') && $name !== $root
        )));

        $core = array_values(array_intersect(self::CORE, $gp247));
        $others = array_values(array_diff($gp247, self::CORE));
        sort($others);

        return array_merge($core, $others);
    }

    /**
     * Format a version as the release tag prints it; branch installs get a short commit hash.
     *
     * @param string|null $pretty    Composer pretty version.
     * @param string|null $reference Source reference (commit hash).
     * @return string Display version, "—" when unknown.
     */
    public static function format(?string $pretty, ?string $reference): string
    {
        if ($pretty === null || $pretty === '') {
            return '—';
        }
        if (str_starts_with($pretty, 'dev-') && $reference) {
            return $pretty . ' (' . substr($reference, 0, self::REFERENCE_LENGTH) . ')';
        }

        return $pretty;
    }

    /**
     * Display version of one installed package.
     *
     * @param string $package Package name.
     * @return string
     */
    private static function display(string $package): string
    {
        return self::format(InstalledVersions::getPrettyVersion($package), InstalledVersions::getReference($package));
    }

    /**
     * Name of the project root package, if Composer knows it.
     *
     * @return string|null
     */
    private static function rootName(): ?string
    {
        return InstalledVersions::getRootPackage()['name'] ?? null;
    }
}
