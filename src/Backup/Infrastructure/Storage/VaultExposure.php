<?php

namespace GP247\Core\Backup\Infrastructure\Storage;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Can the backup folder be downloaded over the web? The folder is always outside `public/`, but
 * some hosts serve the whole project folder (web root set to the project instead of `public/`),
 * and then `storage/…` is reachable. A small "canary" file with a random token is kept in the
 * folder and requested through APP_URL (the configured address — never the request's Host
 * header). Same token back ⇒ exposed.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-doctor-checks
 */
final class VaultExposure
{
    public const CANARY = '.gp247-canary';
    /** admin_config key (global) holding the last probe: {"at": unix time, "exposed": bool|null}. */
    public const RESULT_KEY = 'backup_vault_probe';
    /** A background tick probes again when the stored result is older than this. */
    public const REPROBE_SECONDS = 86400;
    private const TIMEOUT = 5;

    /**
     * Probe and store the result, so the "System alerts" row can show it without any network call
     * while a page renders.
     *
     * @param LocalVault $vault
     * @return bool|null Same as probe().
     *
     * @aidlc-story US-backup-admin-doctor-checks
     */
    public static function probeAndRecord(LocalVault $vault): ?bool
    {
        $exposed = self::probe($vault);
        \GP247\Core\Models\AdminConfig::setConfigValue('backup', self::RESULT_KEY, GP247_STORE_ID_GLOBAL, (string) json_encode(['at' => now()->getTimestamp(), 'exposed' => $exposed]), 0);

        return $exposed;
    }

    /**
     * @return array{at: int, exposed: bool|null}|null Last stored probe, null when never probed.
     *
     * @aidlc-story US-backup-admin-doctor-checks
     */
    public static function recorded(): ?array
    {
        $value = \GP247\Core\Models\AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', self::RESULT_KEY)->value('value');
        $data = is_string($value) ? json_decode($value, true) : null;
        if (!is_array($data) || !isset($data['at'])) {
            return null;
        }

        return ['at' => (int) $data['at'], 'exposed' => isset($data['exposed']) ? (bool) $data['exposed'] : null];
    }

    /**
     * Probe again from a background request (the admin tick) when the stored result is old.
     *
     * @param LocalVault $vault
     * @return void
     *
     * @aidlc-story US-backup-admin-doctor-checks
     */
    public static function refreshIfStale(LocalVault $vault): void
    {
        try {
            $last = self::recorded();
            if ($last === null || now()->getTimestamp() - $last['at'] >= self::REPROBE_SECONDS) {
                self::probeAndRecord($vault);
            }
        } catch (\Throwable $e) {
            // A diagnostic: never break the tick that runs the backups.
        }
    }

    /**
     * @param LocalVault $vault
     * @return bool|null True = downloadable, false = not, null = could not be checked (no answer).
     */
    public static function probe(LocalVault $vault): ?bool
    {
        $root = $vault->root();
        $token = self::canary($root);
        $base = rtrim((string) config('app.url'), '/');
        $project = rtrim(str_replace('\\', '/', base_path()), '/');
        $folder = str_replace('\\', '/', $root);
        if ($base === '' || !preg_match('#^https?://#i', $base) || !str_starts_with($folder, $project . '/')) {
            // A folder outside the project is never under any web root of this site.
            return false;
        }
        $url = $base . '/' . implode('/', array_map('rawurlencode', explode('/', substr($folder, strlen($project) + 1)))) . '/' . self::CANARY;
        try {
            $response = Http::timeout(self::TIMEOUT)->connectTimeout(self::TIMEOUT)->withoutRedirecting()->get($url);
        } catch (\Throwable $e) {
            return null;
        }

        return $response->successful() && hash_equals($token, trim((string) $response->body()));
    }

    /**
     * @param string $root
     * @return string Token of the canary file (created on first use).
     */
    private static function canary(string $root): string
    {
        $file = $root . DIRECTORY_SEPARATOR . self::CANARY;
        $token = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        if (preg_match('/^[A-Za-z0-9]{32}$/D', $token) !== 1) {
            $token = Str::random(32);
            @file_put_contents($file, $token);
        }

        return $token;
    }
}
