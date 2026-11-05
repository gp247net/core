<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Models\AdminLog;

/**
 * One row in the core operation log (`admin_log`, method "BACKUP") for every backup / restore
 * action and its result — refused ones included. Only an allowlist of context keys is written,
 * so a password, recovery key, link token or row of site data can never reach the log, and a
 * logging failure never breaks the action.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-audit-log
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
final class BackupAudit
{
    public const METHOD = 'BACKUP';

    public const OK = 'ok';
    public const DENIED = 'denied';
    public const FAILED = 'failed';
    public const REFUSED = 'refused';

    /** Actor id written when no admin is signed in (CLI, BK-5). */
    public const NO_ADMIN_ID = '00000000-0000-0000-0000-000000000000';

    private const CONTEXT_KEYS = ['archive_id', 'job_id', 'kind', 'level', 'groups', 'reason', 'right', 'path', 'method', 'retry_after', 'pinned', 'status', 'tables', 'actor'];

    /**
     * @param string               $action  create | verify | pin | delete | download_link | download | restore_preview | restore | undo | key_setup | key_change | recovery_key | step_up | settings | denied_request…
     * @param string               $result  ok | denied | failed | refused.
     * @param array<string, mixed> $context Only CONTEXT_KEYS are kept.
     * @return void
     */
    public static function record(string $action, string $result, array $context = []): void
    {
        try {
            $user = admin()->user();
            $input = ['action' => $action, 'result' => $result] + self::safe($context);
            if ($user === null) {
                $input['actor'] ??= app()->runningInConsole() ? 'cli' : 'guest';
            }
            AdminLog::create([
                'user_id' => $user !== null ? (string) $user->id : self::NO_ADMIN_ID,
                'path' => mb_substr(GP247_ADMIN_PREFIX . '/backup/' . $action, 0, 255),
                'method' => self::METHOD,
                'ip' => (string) (gp247_get_real_ip_client() ?? ''),
                'user_agent' => mb_substr((string) request()->header('User-Agent', ''), 0, 255),
                'input' => (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);
        } catch (\Throwable $e) {
            gp247_report('Backup audit row not written: ' . $e->getMessage());
        }
    }

    /**
     * A request to the backup area refused by the permission middleware (it runs before the
     * request log, which therefore never sees refusals).
     *
     * @param string $path   Request path.
     * @param string $method HTTP method.
     * @return void
     */
    public static function deniedRequest(string $path, string $method): void
    {
        self::record('denied_request', self::DENIED, ['path' => mb_substr($path, 0, 200), 'method' => $method]);
    }

    /**
     * @param string $path Request path without leading slash.
     * @return bool Whether the path is in the backup area.
     */
    public static function isBackupPath(string $path): bool
    {
        $base = GP247_ADMIN_PREFIX . '/backup';

        return $path === $base || str_starts_with($path, $base . '/');
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function safe(array $context): array
    {
        $out = [];
        foreach (self::CONTEXT_KEYS as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            $out[$key] = is_scalar($value) || $value === null
                ? (is_string($value) ? mb_substr($value, 0, 200) : $value)
                : array_slice(array_map(static fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 100) : '…', array_values((array) $value)), 0, 50);
        }

        return $out;
    }
}
