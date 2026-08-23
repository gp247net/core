<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;

/**
 * Base for the gp247:ext-* command family. Adds --type resolution shared by
 * every extension lifecycle command (plugin|template → Plugins|Templates).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_service-extraction
 */
abstract class ExtCommand extends GP247Command
{
    /**
     * Resolve the --type option to the canonical group type, or null when the
     * value is missing/invalid.
     *
     * @return string|null 'Plugins' | 'Templates' | null.
     */
    protected function resolveType(): ?string
    {
        $t = strtolower((string) $this->option('type'));
        if (in_array($t, ['plugin', 'plugins'], true)) {
            return 'Plugins';
        }
        if (in_array($t, ['template', 'templates'], true)) {
            return 'Templates';
        }
        return null;
    }

    /**
     * Standard failure for an invalid/missing --type.
     *
     * @return int Command::FAILURE.
     */
    protected function failInvalidType(): int
    {
        return $this->respondFailure('invalid_type', 'Option --type must be "plugin" or "template".');
    }

    /**
     * Collect a repeatable/comma-separated option into a de-duplicated list.
     *
     * Accepts both `--key=A --key=B` (repeated) and `--key=A,B` (comma-joined).
     *
     * @param string $name Option name.
     * @return array<int, string> Non-empty trimmed values.
     */
    protected function optionList(string $name): array
    {
        $out = [];
        foreach ((array) $this->option($name) as $value) {
            foreach (explode(',', (string) $value) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $out[] = $part;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Apply a per-item lifecycle operation across a list of keys, one at a time,
     * collecting per-item results. Each item is isolated: one failure never
     * aborts the rest (industry-standard batch semantics).
     *
     * @param array<int, string> $keys     Extension keys to process.
     * @param \Closure            $apply    fn(string $key): array — returns {error,msg}.
     * @param string              $doneVerb Human past-tense verb for success lines.
     * @return array{succeeded: array<int, string>, failed: array<string, string>}
     */
    protected function applyBatch(array $keys, \Closure $apply, string $doneVerb): array
    {
        $succeeded = [];
        $failed = [];
        foreach ($keys as $key) {
            $result = $apply($key);
            if (is_array($result) && ($result['error'] ?? 1) == 0) {
                $succeeded[] = $key;
                $this->info(ucfirst($doneVerb).': '.$key);
            } else {
                $failed[$key] = is_array($result) ? ($result['msg'] ?? 'failed') : 'failed';
                $this->addWarning('Failed '.$key.': '.$failed[$key]);
            }
        }
        return ['succeeded' => $succeeded, 'failed' => $failed];
    }
}
