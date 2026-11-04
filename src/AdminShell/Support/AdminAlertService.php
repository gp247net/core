<?php

namespace GP247\Core\AdminShell\Support;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use Illuminate\Support\Facades\Cache;

/**
 * Evaluates the registered alert checks for the "System alerts" block.
 *
 * Every public entry point answers only for an Administrator (the list of a
 * site's weak spots is sensitive — NFR-SEC-admin-alert-administrator-only).
 * Each check result is cached for its own ttl; the header count reads the
 * cache only so ordinary admin pages never run a check
 * (NFR-PERF-admin-alert-non-blocking). A check that throws is skipped and
 * reported, never shown as passed.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 * @aidlc-adr admin-shell_system-alert-registry
 */
class AdminAlertService
{
    private const PREFIX = 'gp247_admin_alert';

    /**
     * Whether the current admin may see alerts (Administrator role only).
     *
     * @return bool
     */
    public function allowed(): bool
    {
        try {
            return app(AdminUserContract::class)->isAdministrator();
        } catch (\Throwable $e) {
            // WHY: no resolvable admin (guest, console) means no audience — fail closed.
            return false;
        }
    }

    /**
     * Current alerts, failing first (critical → warning → info → ok).
     *
     * @return array{checkedAt: int|null, items: array<int, AdminAlert>}
     */
    public function results(): array
    {
        if (!$this->allowed()) {
            return ['checkedAt' => null, 'items' => []];
        }

        $items = [];
        $checkedAt = null;
        $position = 0;
        $version = $this->version();
        foreach (AdminAlertRegistry::all() as $key => $class) {
            $entry = $this->cachedOrEvaluate($key, $class, $version);
            if ($entry === null) {
                continue;
            }
            $checkedAt = $checkedAt === null ? $entry['at'] : min($checkedAt, $entry['at']);
            $items[] = ['alert' => $entry['alert'], 'position' => $position++];
        }

        usort($items, static fn (array $a, array $b): int => [$a['alert']->weight(), $a['position']] <=> [$b['alert']->weight(), $b['position']]);

        return ['checkedAt' => $checkedAt, 'items' => array_column($items, 'alert')];
    }

    /**
     * Number of critical alerts among cached results; never evaluates a check.
     *
     * @return int
     */
    public function criticalCount(): int
    {
        if (!$this->allowed()) {
            return 0;
        }

        $count = 0;
        $version = $this->version();
        foreach (array_keys(AdminAlertRegistry::all()) as $key) {
            if (Cache::get($this->levelKey($key, $version)) === AdminAlert::CRITICAL) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Drop every cached result so the next read evaluates again ("Check again").
     *
     * @return void
     */
    public function refresh(): void
    {
        if (!$this->allowed()) {
            return;
        }

        // WHY a version stamp: results are cached per locale; bumping one number
        // invalidates every locale's copy without enumerating them.
        Cache::forever(self::PREFIX . ':version', $this->version() + 1);
    }

    /**
     * @param string                        $key
     * @param class-string<AdminAlertCheck> $class
     * @param int                           $version Cache generation (bumped by refresh()).
     * @return array{at: int, alert: AdminAlert}|null Null when the check failed.
     */
    private function cachedOrEvaluate(string $key, string $class, int $version): ?array
    {
        $cached = Cache::get($this->resultKey($key, $version));
        if (is_array($cached) && isset($cached['at'], $cached['alert'])) {
            try {
                return ['at' => (int) $cached['at'], 'alert' => AdminAlert::fromArray((array) $cached['alert'])];
            } catch (\InvalidArgumentException $e) {
                // WHY: a corrupt cache entry is simply evaluated again below.
            }
        }

        try {
            /** @var AdminAlertCheck $check */
            $check = app($class);
            $alert = $check->evaluate();
            $ttl = max(1, $check->ttl());
        } catch (\Throwable $e) {
            $this->report('[gp247 alerts] check "' . $key . '" failed: ' . $e->getMessage());

            return null;
        }

        $at = now()->getTimestamp();
        Cache::put($this->resultKey($key, $version), ['at' => $at, 'alert' => $alert->toArray()], $ttl);
        Cache::put($this->levelKey($key, $version), $alert->level, $ttl);

        return ['at' => $at, 'alert' => $alert];
    }

    /**
     * Per-locale key of a full result (messages are translated).
     *
     * @param string $key
     * @param int    $version
     * @return string
     */
    private function resultKey(string $key, int $version): string
    {
        $locale = function_exists('gp247_get_locale') ? (string) gp247_get_locale() : app()->getLocale();

        return self::PREFIX . ':' . $version . ':' . $locale . ':' . $key;
    }

    /**
     * Locale-independent key of a result's level (read by the header count).
     *
     * @param string $key
     * @param int    $version
     * @return string
     */
    private function levelKey(string $key, int $version): string
    {
        return self::PREFIX . ':' . $version . ':level:' . $key;
    }

    /**
     * @return int
     */
    private function version(): int
    {
        return (int) Cache::get(self::PREFIX . ':version', 0);
    }

    /**
     * @param string $message
     * @return void
     */
    private function report(string $message): void
    {
        // WHY guarded: GP247 code logs through gp247_report(); the helper exists whenever an
        // admin can reach this service (helpers load on an installed site).
        if (function_exists('gp247_report')) {
            gp247_report($message);
        }
    }
}
