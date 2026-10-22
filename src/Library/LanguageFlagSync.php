<?php

namespace GP247\Core\Library;

use Throwable;

/**
 * Copy language flag icons that the published site is missing.
 *
 * gp247:core-update seeds new default languages (e.g. ja with flag_jp.png) on
 * installed sites, while republishing core assets is opt-in (--publish
 * core-public). Copying only the missing files gives the new language its icon
 * without overwriting an icon the site owner replaced.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-upgrade-migration-delivery
 * @aidlc-adr compat-foundation_locale-data-files
 */
class LanguageFlagSync
{
    /**
     * Copy every file of $source that does not exist in $target.
     *
     * @param string $source Package flag directory.
     * @param string $target Published flag directory (created when absent).
     * @return array{copied: array<int, string>, failed: array<int, string>} File names.
     */
    public static function copyMissing(string $source, string $target): array
    {
        $result = ['copied' => [], 'failed' => []];
        if (!is_dir($source)) {
            return $result;
        }
        if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
            $result['failed'] = array_map('basename', glob($source.'/*') ?: []);

            return $result;
        }

        foreach (glob($source.'/*') ?: [] as $file) {
            $name = basename($file);
            if (!is_file($file) || file_exists($target.'/'.$name)) {
                continue;
            }
            try {
                $ok = @copy($file, $target.'/'.$name);
            } catch (Throwable $e) {
                $ok = false;
            }
            $result[$ok ? 'copied' : 'failed'][] = $name;
        }

        return $result;
    }
}
