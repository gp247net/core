<?php

namespace GP247\Core\PersonalData\Infrastructure\Export;

/**
 * Where data packages are built and kept until downloaded: a folder under storage/ (never under
 * the web root), one working folder and one ZIP per package. Paths are derived from the package
 * id only — never from a file name sent by a browser.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-export-package
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class ExportStorage
{
    /**
     * @return string
     */
    public static function base(): string
    {
        return storage_path('app/private/gp247/personal_data/exports');
    }

    /**
     * @param string $id Package id (ULID).
     * @return string Working folder of the package (created when missing).
     * @throws \InvalidArgumentException When the id is not a package id.
     */
    public static function workDir(string $id): string
    {
        $dir = self::base() . DIRECTORY_SEPARATOR . self::checked($id);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('The folder of the data package cannot be created.');
        }

        return $dir;
    }

    /**
     * @param string $id
     * @return string
     */
    public static function zipPath(string $id): string
    {
        return self::base() . DIRECTORY_SEPARATOR . self::checked($id) . '.zip';
    }

    /**
     * Remove the working folder of a package (the collected rows), keeping its ZIP.
     *
     * @param string $id
     * @return void
     */
    public static function removeWorkDir(string $id): void
    {
        $dir = self::base() . DIRECTORY_SEPARATOR . self::checked($id);
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($dir);
        }
    }

    /**
     * Remove the working folder and the ZIP of a package.
     *
     * @param string $id
     * @return void
     */
    public static function remove(string $id): void
    {
        self::removeWorkDir($id);
        $zip = self::zipPath($id);
        if (is_file($zip)) {
            @unlink($zip);
        }
        foreach (glob($zip . '.*.part') ?: [] as $partial) {
            @unlink($partial);
        }
    }

    /**
     * @param string $id
     * @return string
     * @throws \InvalidArgumentException
     */
    private static function checked(string $id): string
    {
        if (preg_match('/^[0-9A-Za-z]{26}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid package id');
        }

        return $id;
    }
}
