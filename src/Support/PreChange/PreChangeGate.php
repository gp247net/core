<?php

namespace GP247\Core\Support\PreChange;

/**
 * Asks every "before change" registrant (config `gp247-config.core.pre_change`) whether a risky
 * change may run. Entries are a callable, or `['key' => string, 'callback' => callable]`, appended by
 * a package or plugin at boot (the same runtime-append idiom as `front.plugin_hooks`).
 *
 * A registrant that throws or answers with something other than a {@see PreChangeResult} counts as a
 * stop: losing the chance to snapshot silently is worse than asking the person to confirm.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-pre-change-hook
 * @aidlc-adr system-cli_pre-change-registry
 */
final class PreChangeGate
{
    /**
     * @param PreChange       $change
     * @param array|null      $registrants Registrants to ask; null = the core config key.
     * @return PreChangeOutcome
     */
    public function run(PreChange $change, ?array $registrants = null): PreChangeOutcome
    {
        $registrants ??= (array) config('gp247-config.core.pre_change', []);

        $entries = [];
        foreach ($registrants as $index => $registrant) {
            [$key, $callback] = $this->normalize($index, $registrant);
            if ($callback === null) {
                continue;
            }
            $entries[] = $this->ask($key, $callback, $change);
        }

        return new PreChangeOutcome($entries);
    }

    /**
     * @param int|string $index
     * @param mixed      $registrant
     * @return array{0: string, 1: ?callable}
     */
    private function normalize(int|string $index, mixed $registrant): array
    {
        if (is_array($registrant) && array_key_exists('callback', $registrant)) {
            $callback = is_callable($registrant['callback']) ? $registrant['callback'] : null;

            return [(string) ($registrant['key'] ?? $index), $callback];
        }

        // WHY not is_callable() on arrays without 'callback': ['key' => 'x'] is not a callable,
        // but [$object, 'method'] is — keep the plain-callable form working.
        return [(string) $index, is_callable($registrant) ? $registrant : null];
    }

    /**
     * @param string    $key
     * @param callable  $callback
     * @param PreChange $change
     * @return array{key: string, proceed: bool, reason: ?string, notice: ?string, pending_job: ?string, error: ?string}
     */
    private function ask(string $key, callable $callback, PreChange $change): array
    {
        try {
            $result = $callback($change);
        } catch (\Throwable $e) {
            gp247_report(msg: 'Pre-change registrant "'.$key.'" failed on '.$change->operation.': '.$e->getMessage(), channel: null);

            return $this->entry($key, false, $this->message('admin.pre_change.registrant_failed', $key), null, null, $e->getMessage());
        }

        if (!$result instanceof PreChangeResult) {
            return $this->entry($key, false, $this->message('admin.pre_change.invalid_result', $key), null, null, 'invalid result: '.get_debug_type($result));
        }

        return $this->entry($key, $result->proceed, $result->reason, $result->notice, $result->pendingJob, null);
    }

    /**
     * @return array{key: string, proceed: bool, reason: ?string, notice: ?string, pending_job: ?string, error: ?string}
     */
    private function entry(string $key, bool $proceed, ?string $reason, ?string $notice, ?string $pendingJob, ?string $error): array
    {
        return ['key' => $key, 'proceed' => $proceed, 'reason' => $reason, 'notice' => $notice, 'pending_job' => $pendingJob, 'error' => $error];
    }

    /**
     * @param string $code Language key.
     * @param string $key  Registrant key.
     * @return string
     */
    private function message(string $code, string $key): string
    {
        $text = function_exists('gp247_language_render') ? (string) gp247_language_render($code, ['key' => $key]) : '';

        // WHY fallback: the language table can be missing (CLI before install) or lack the new key.
        return ($text !== '' && $text !== $code) ? $text : 'Pre-change check "'.$key.'" could not run';
    }
}
