<?php

namespace GP247\Core\Support;

/**
 * Registry of bulk writes that are running right now — the backup area of the core (backup,
 * restore, archive import) and extensions (e.g. a data import writing rows). Each side registers a
 * probe and asks the registry before it starts, so two bulk writes never run at the same time.
 *
 * Sources: config `gp247-config.core.write_activity` (list of `['key' => string, 'probe' => callable]`,
 * appended at boot like `pre_change`) and register() at runtime. A probe returns null when nothing
 * of its own is writing, or `['label' => string, 'until' => ?CarbonInterface]` while it writes;
 * `until` is the end of its lease, so a write that died does not block for ever.
 *
 * Lives outside the Backup module: it works when the backup feature is off
 * (NFR-MAINT-backup-module-isolation).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-write-activity-exclusion
 * @aidlc-adr backup-engine_write-activity-registry
 */
final class WriteActivity
{
    private const CONFIG_KEY = 'gp247-config.core.write_activity';

    /**
     * Add a probe (appended to the config key, so a later boot step or test can still see it).
     *
     * @param string   $key   Who writes (e.g. 'backup', 'data-import').
     * @param callable $probe fn(): ?array{label?: string, until?: ?\DateTimeInterface}
     * @return void
     */
    public static function register(string $key, callable $probe): void
    {
        $entries = (array) config(self::CONFIG_KEY, []);
        $entries[] = ['key' => $key, 'probe' => $probe];
        config([self::CONFIG_KEY => $entries]);
    }

    /**
     * The first registrant (other than $except) that reports a bulk write in progress.
     *
     * A probe that throws counts as "not writing" and is reported: a broken extension must not
     * stop the site's backups.
     *
     * @param string|null $except Key of the asking side (its own write is not a conflict).
     * @return array{key: string, label: string}|null Null when nothing else is writing.
     */
    public static function busy(?string $except = null): ?array
    {
        foreach ((array) config(self::CONFIG_KEY, []) as $index => $entry) {
            $key = (string) (is_array($entry) ? ($entry['key'] ?? $index) : $index);
            $probe = is_array($entry) && array_key_exists('probe', $entry) ? $entry['probe'] : null;
            if ($key === $except || !is_callable($probe)) {
                continue;
            }
            $report = self::ask($key, $probe);
            if ($report !== null) {
                return $report;
            }
        }

        return null;
    }

    /**
     * @param string   $key
     * @param callable $probe
     * @return array{key: string, label: string}|null
     */
    private static function ask(string $key, callable $probe): ?array
    {
        try {
            $answer = $probe();
        } catch (\Throwable $e) {
            if (function_exists('gp247_report')) {
                gp247_report(msg: 'Write activity probe "' . $key . '" failed: ' . $e->getMessage(), channel: null);
            }

            return null;
        }
        if (!is_array($answer)) {
            return null;
        }
        $until = $answer['until'] ?? null;
        if ($until instanceof \DateTimeInterface && $until->getTimestamp() < time()) {
            return null;
        }
        $label = trim((string) ($answer['label'] ?? ''));

        return ['key' => $key, 'label' => $label !== '' ? $label : $key];
    }
}
