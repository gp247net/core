<?php

namespace GP247\Core\Support;

/**
 * Where a storefront template's files come from, and how much of a published
 * copy has stopped receiving updates.
 *
 * A template's Blade lives in the package that ships it and is served through
 * the GP247TemplatePath view namespace, which carries one hint path per source.
 * A file only lands in app/GP247/Templates when the site publishes it to edit
 * it — and from then on it shadows the package for ever. Files published but
 * never edited are therefore invisible debt: no `composer update` can reach them
 * again. This class is what counts them.
 *
 * WHY a class and not a gp247_* helper: gp247:doctor runs in the bootstrap tier,
 * before the platform is installed and before helper files are loaded, so it may
 * only rely on PSR-4 classes and framework APIs (ADR
 * system-cli_command-registration-tiers).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-template-source-lifecycle
 * @aidlc-adr frontend-template-dev_template-vendor-resident-views
 * @aidlc-adr system-cli_command-registration-tiers
 */
class TemplateSourceAudit
{
    /** View namespace under which every template source root is registered. */
    public const NAMESPACE = 'GP247TemplatePath';

    /**
     * Entries (relative to a template root) forming a template's extension
     * SHELL: the files core needs on disk under app/ to discover, activate and
     * boot the template — it globs app_path() for AppConfig.php and autoloads
     * the class as App\GP247\Templates\<Name>\AppConfig.
     *
     * WHY core owns this list: the shell IS core's extension format (the same
     * shape plugins use), and three places must agree on it — what an install
     * publishes, what gp247:template-prune refuses to delete, and what this
     * audit must not report as stale debt.
     *
     * @var array<int, string>
     */
    public const SHELL_ENTRIES = [
        'AppConfig.php',
        'Provider.php',
        'Route.php',
        'config.php',
        'function.php',
        'gp247.json',
        'Lang',
    ];

    /**
     * Template source roots, in the order the view finder searches them.
     *
     * @param bool $includeApp Keep app/GP247/Templates (the published side); false = package sources only.
     * @return array<int, string> Absolute directory paths with forward slashes, highest priority first.
     */
    public static function roots(bool $includeApp = true): array
    {
        try {
            $hints = view()->getFinder()->getHints()[self::NAMESPACE] ?? [];
        } catch (\Throwable $e) {
            return [];
        }

        $appRoot = self::normalize(app_path('GP247/Templates'));
        $roots = [];

        foreach ((array) $hints as $hint) {
            $hint = self::normalize((string) $hint);
            if ($hint === '' || in_array($hint, $roots, true)) {
                continue;
            }
            // WHY normalized before comparing: app_path() uses DIRECTORY_SEPARATOR
            // while the hint was registered as app_path().'/GP247/Templates', so on
            // Windows the raw strings differ. Failing to recognize the app root
            // would make it look like a package source — and then every published
            // file would be "identical to the package", i.e. safe to delete.
            if (!$includeApp && $hint === $appRoot) {
                continue;
            }
            $roots[] = $hint;
        }

        return $roots;
    }

    /**
     * Classify every published template file against the package copy.
     *
     * @param string|null $template Restrict to one template, or null for all published ones.
     * @return array{identical: int, edited: int, own: int} identical = can be handed back to its package,
     *         edited = customized by the site, own = provided by no package (plugin blocks, site files).
     */
    public static function stats(?string $template = null): array
    {
        $stats = ['identical' => 0, 'edited' => 0, 'own' => 0];

        $roots = self::roots(false);
        if (empty($roots)) {
            return $stats;
        }

        $appRoot = app_path('GP247/Templates');
        $published = $template === null
            ? (glob($appRoot.'/*', GLOB_ONLYDIR) ?: [])
            : (is_dir($appRoot.'/'.$template) ? [$appRoot.'/'.$template] : []);

        foreach ($published as $directory) {
            $name = basename($directory);

            foreach (self::files($directory) as $file) {
                $relative = ltrim(str_replace('\\', '/', substr($file, strlen($directory) + 1)), '/');

                // WHY the shell is excluded: it is published ON PURPOSE and
                // gp247:template-prune will never delete it. Counting it as
                // "identical, hand it back" would make doctor nag for ever about
                // something no command is allowed to act on.
                if (self::isShellEntry($relative)) {
                    continue;
                }

                $counterpart = null;
                foreach ($roots as $root) {
                    $candidate = $root.'/'.$name.'/'.$relative;
                    if (is_file($candidate)) {
                        $counterpart = $candidate;
                        break;
                    }
                }

                if ($counterpart === null) {
                    $stats['own']++;
                } elseif (@hash_file('sha256', $file) === @hash_file('sha256', $counterpart)) {
                    $stats['identical']++;
                } else {
                    $stats['edited']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Whether a path inside a template belongs to its extension shell.
     *
     * @param string $relative Path relative to the template directory.
     * @return bool
     */
    public static function isShellEntry(string $relative): bool
    {
        foreach (self::SHELL_ENTRIES as $entry) {
            if ($relative === $entry || str_starts_with($relative, $entry.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * List every file under a directory, recursively.
     *
     * @param string $directory Absolute directory path.
     * @return array<int, string> Absolute file paths (empty when it is not a directory).
     */
    public static function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_dir($path)) {
                $found = array_merge($found, self::files($path));
            } else {
                $found[] = $path;
            }
        }

        return $found;
    }

    /**
     * Normalize a path for comparison: forward slashes, no trailing separator.
     *
     * @param string $path Any filesystem path.
     * @return string
     */
    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
