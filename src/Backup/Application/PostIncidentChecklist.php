<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Infrastructure\Store\AdminConfigKeyStore;
use GP247\Core\Models\AdminConfig;

/**
 * What the site owner still has to do by hand after a post-incident restore, kept until an
 * Administrator marks it done (shown on the backup screen and by gp247:doctor). Stored in
 * admin_config under a "backup_" key, so a later configuration restore keeps it.
 *
 * The system never writes .env: rotating the site encryption key is a task with the exact
 * variable names and command.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-post-incident-mode
 */
final class PostIncidentChecklist
{
    public const KEY = 'backup_post_incident';

    /** Admin sessions signed in before this moment are ended on their next request (any session driver). */
    public const SESSIONS_VALID_AFTER = 'backup_sessions_valid_after';

    /** Session key: when this admin session signed in (set by the admin login). */
    public const SESSION_AUTH_AT = 'gp247_admin_auth_at';

    /** @var int|false|null Memo of SESSIONS_VALID_AFTER for the current request (false = none). */
    private static int|false|null $validAfter = null;

    /** @var list<string> Tasks, in the order shown (texts: BackupScreenLanguage "backup.post_incident.task_*"). */
    public const TASKS = ['rotate_key', 'reinstall_code', 'change_hosting_passwords', 'run_doctor'];

    /** Commands and variable names quoted by the tasks (shown verbatim, never translated). */
    public const ROTATE_VARIABLES = ['key' => 'GP247_ENCRYPTION_KEY', 'previous' => 'GP247_ENCRYPTION_PREVIOUS_KEYS'];
    public const ROTATE_COMMAND = 'php artisan gp247:encryption-key-rotate';
    public const DOCTOR_COMMAND = 'php artisan gp247:doctor';

    /**
     * End every admin session signed in before now, whatever the session driver: the admin
     * guard checks the moment each session signed in (Middleware\Authenticate).
     *
     * @return void
     *
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    public static function endAdminSessions(): void
    {
        AdminConfig::setConfigValue(AdminConfigKeyStore::GROUP, self::SESSIONS_VALID_AFTER, GP247_STORE_ID_GLOBAL, (string) now()->getTimestamp());
        self::$validAfter = null;
    }

    /**
     * @param int|null $signedInAt Unix time the session signed in (null = unknown: signed in before this release).
     * @return bool The session signed in before the last post-incident restore and must end.
     *
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    public static function sessionEnded(?int $signedInAt): bool
    {
        if (self::$validAfter === null) {
            try {
                $raw = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', self::SESSIONS_VALID_AFTER)->value('value');
            } catch (\Throwable $e) {
                $raw = null;
            }
            self::$validAfter = is_numeric($raw) ? (int) $raw : false;
        }

        return self::$validAfter !== false && ($signedInAt === null || $signedInAt < self::$validAfter);
    }

    /**
     * @param int         $jobId
     * @param list<array> $steps Results of the automatic steps.
     * @return void
     *
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    public static function open(int $jobId, array $steps): void
    {
        self::write([
            'job_id' => $jobId,
            'at' => now()->toIso8601String(),
            'steps' => array_values($steps),
            'tasks' => self::TASKS,
            'done_at' => null,
        ]);
    }

    /**
     * @return array<string, mixed>|null The open checklist, null when there is none or it is done.
     *
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    public static function pending(): ?array
    {
        try {
            $raw = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', self::KEY)->value('value');
        } catch (\Throwable $e) {
            return null;
        }
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($data) && empty($data['done_at']) ? $data : null;
    }

    /**
     * @param string $by Who marked it done.
     * @return bool False when nothing was pending.
     *
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    public static function markDone(string $by): bool
    {
        $data = self::pending();
        if ($data === null) {
            return false;
        }
        $data['done_at'] = now()->toIso8601String();
        $data['done_by'] = mb_substr($by, 0, 100);
        self::write($data);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    private static function write(array $data): void
    {
        AdminConfig::setConfigValue(AdminConfigKeyStore::GROUP, self::KEY, GP247_STORE_ID_GLOBAL, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
