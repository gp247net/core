<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\Support\RawOutputScanner;

/**
 * Site templates and plugins (app/GP247/Templates, app/GP247/Plugins) that print a variable
 * with {!! … !!} outside the known trusted forms. Since core 3.12 text is stored as typed, so
 * such a print shows whatever markup a person typed — the owner should switch it to {{ }}.
 * Reads files only (no database, no tool beyond PHP); the default GP247Front views served
 * from vendor/ are not scanned (they have their own guard).
 *
 * Doctor row `raw_output_scan`; `--json` carries the full `file:line` list.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-doctor-raw-output-scan
 * @aidlc-adr compat-foundation_text-stored-as-typed
 */
class RawOutputScanCheck implements AdminAlertCheck
{
    private const SHOWN = 3;

    /** @var array<int, string>|null */
    private ?array $roots;

    /**
     * @param array<int, string>|null $roots Folders to scan; default: the site's templates and plugins.
     */
    public function __construct(?array $roots = null)
    {
        $this->roots = $roots;
    }

    /** @return string */
    public function key(): string
    {
        return 'raw_output_scan';
    }

    /** @return int */
    public function ttl(): int
    {
        return 3600;
    }

    /**
     * @return AdminAlert
     */
    public function evaluate(): AdminAlert
    {
        $found = $this->findings();
        if ($found === []) {
            return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.raw_output_scan_ok'), 'no raw print of a variable in site templates or plugins');
        }

        $shown = array_slice($found, 0, self::SHOWN);
        $detail = count($found) . ' raw print(s) {!! $… !!} in site templates/plugins may show markup a person typed: '
            . implode(', ', $shown)
            . (count($found) > self::SHOWN ? ' (+' . (count($found) - self::SHOWN) . ' more)' : '')
            . ' — print text with {{ }} (keep {!! !!} only for HTML you trust)';

        return AdminAlert::warning(
            $this->key(),
            HealthText::t('admin.alerts.raw_output_scan', ['count' => count($found)]),
            $detail,
            docUrl: HealthText::doc('security-updates-and-incidents'),
            items: $found,
        );
    }

    /**
     * @return array<int, string> "<folder>/<path>:<line>" of each untrusted raw print.
     */
    private function findings(): array
    {
        $found = [];
        foreach ($this->roots ?? [app_path('GP247/Templates'), app_path('GP247/Plugins')] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $base = dirname($root);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                foreach (RawOutputScanner::rawPrints((string) file_get_contents($file->getPathname())) as $print) {
                    if (!RawOutputScanner::isTrustedForm($print['expr'])) {
                        $found[] = $relative . ':' . $print['line'];
                    }
                }
            }
        }
        sort($found);

        return $found;
    }
}
