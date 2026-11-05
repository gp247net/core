<?php

namespace GP247\Core\Backup\Domain;

/**
 * Decides whether an archive's data fits the schema of the running site, from the migration
 * lists (the authority — ADR shadow-table-swap §7) and, for the message, the package versions.
 *
 *  - the archive ran a migration the site has not       ⇒ newer: refused, the site must be updated first;
 *  - the site ran migrations the archive does not know   ⇒ older: allowed, data update afterwards;
 *  - same list                                           ⇒ same;
 *  - the archive carries no migration list               ⇒ unknown: allowed with a warning, data update afterwards.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-version-compatibility
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class CompatibilityGate
{
    public const SAME = 'same';
    public const OLDER = 'older';
    public const NEWER = 'newer';
    public const UNKNOWN = 'unknown';

    /**
     * @param list<string>          $archiveMigrations meta.migrations of the archive.
     * @param list<string>          $siteMigrations    Migrations run on this site.
     * @param array<string, string> $archivePackages   meta.packages (package => version).
     * @param array<string, string> $sitePackages      Running package versions.
     * @return array{status: string, missing_on_site: list<string>, newer_on_site: list<string>,
     *               packages: list<array{package: string, archive: string, site: string|null}>}
     */
    public static function check(array $archiveMigrations, array $siteMigrations, array $archivePackages, array $sitePackages): array
    {
        $archive = array_values(array_unique(array_map('strval', $archiveMigrations)));
        $site = array_values(array_unique(array_map('strval', $siteMigrations)));
        $missing = array_values(array_diff($archive, $site));
        $newerOnSite = array_values(array_diff($site, $archive));

        $status = match (true) {
            $archive === [] => self::UNKNOWN,
            $missing !== [] => self::NEWER,
            $newerOnSite !== [] => self::OLDER,
            default => self::SAME,
        };

        return [
            'status' => $status,
            'missing_on_site' => $missing,
            'newer_on_site' => $newerOnSite,
            'packages' => self::newerPackages($archivePackages, $sitePackages),
        ];
    }

    /**
     * Packages the archive was made with at a higher version than the site runs — the
     * "update this package to at least …" part of a refusal. Versions that cannot be compared
     * (dev branches) are left out; the migration list still decides.
     *
     * @param array<string, string> $archivePackages
     * @param array<string, string> $sitePackages
     * @return list<array{package: string, archive: string, site: string|null}>
     */
    public static function newerPackages(array $archivePackages, array $sitePackages): array
    {
        $out = [];
        foreach ($archivePackages as $package => $version) {
            $archive = self::normalise((string) $version);
            if ($archive === null) {
                continue;
            }
            $siteVersion = isset($sitePackages[$package]) ? (string) $sitePackages[$package] : null;
            $site = $siteVersion === null ? null : self::normalise($siteVersion);
            if ($siteVersion !== null && $site === null) {
                continue;
            }
            if ($site === null || version_compare($archive, $site, '>')) {
                $out[] = ['package' => (string) $package, 'archive' => (string) $version, 'site' => $siteVersion];
            }
        }

        return $out;
    }

    /**
     * @param string $version "3.10", "v3.10.2", "3.10.2.0"…
     * @return string|null Comparable numeric version, null for dev branches and the like.
     */
    private static function normalise(string $version): ?string
    {
        $version = ltrim(trim($version), 'vV');

        return preg_match('/^\d+(\.\d+){0,3}$/D', $version) === 1 ? $version : null;
    }
}
