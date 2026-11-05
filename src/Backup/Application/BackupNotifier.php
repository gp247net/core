<?php

namespace GP247\Core\Backup\Application;

/**
 * E-mails to the site owner (root store e-mail) about the backup feature: a backup failed,
 * someone downloaded an archive, someone restored. Uses the site's own mail settings; when mail
 * is switched off nothing is sent (the backup screen warns), and a sending error never breaks
 * the action that triggered it. The "no recent backup" mail comes with the schedule (BK-7).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-notifications
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
class BackupNotifier
{
    public const VIEW = 'gp247-admin::email.backup_notice';

    /**
     * @return bool Whether the site sends mail at all.
     */
    public static function mailEnabled(): bool
    {
        return !empty(gp247_config('email_action_mode', GP247_STORE_ID_ROOT));
    }

    /**
     * @param string $archiveId
     * @param string $error Short error description (never row data).
     * @return bool Whether the mail was accepted for delivery.
     */
    public function backupFailed(string $archiveId, string $error): bool
    {
        return $this->send('failed', [
            'archive' => $archiveId,
            'error' => mb_substr($error, 0, 200),
        ]);
    }

    /**
     * @param object $archive  Catalogue row.
     * @param string $username Admin who downloaded.
     * @return bool
     */
    public function downloaded(object $archive, string $username): bool
    {
        return $this->send('downloaded', [
            'archive' => (string) $archive->id,
            'kind' => (string) $archive->kind,
            'user' => $username,
            'ip' => (string) (gp247_get_real_ip_client() ?? ''),
        ]);
    }

    /**
     * @param string $archiveId
     * @param string $level    Restore level.
     * @param string $username
     * @return bool
     */
    public function restoreStarted(string $archiveId, string $level, string $username): bool
    {
        return $this->send('restore_started', [
            'archive' => $archiveId,
            'level' => $level,
            'user' => $username,
            'ip' => (string) (gp247_get_real_ip_client() ?? ''),
        ]);
    }

    /**
     * @param string|null $archiveId
     * @param string      $status done | failed | cancelled
     * @param string|null $error
     * @return bool
     */
    public function restoreFinished(?string $archiveId, string $status, ?string $error = null): bool
    {
        return $this->send('restore_finished', [
            'archive' => (string) $archiveId,
            'status' => $status,
            'error' => mb_substr((string) $error, 0, 200),
        ]);
    }

    /**
     * @param string                $event
     * @param array<string, string> $facts Replacements for the language strings.
     * @return bool
     */
    protected function send(string $event, array $facts): bool
    {
        try {
            if (!self::mailEnabled()) {
                return false;
            }
            $to = (string) gp247_store_info('email', null, GP247_STORE_ID_ROOT);
            if ($to === '') {
                return false;
            }
            $site = (string) (gp247_store_info('title', null, GP247_STORE_ID_ROOT) ?: config('app.name'));
            $facts += ['site' => $site, 'time' => now()->toDateTimeString()];
            $prefix = 'admin.backup.mail.' . $event;

            return gp247_mail_send(self::VIEW, [
                'title' => gp247_language_render($prefix . '_title', $facts),
                'lines' => [gp247_language_render($prefix . '_body', $facts)],
                'note' => gp247_language_render('admin.backup.mail.note', $facts),
                'link' => gp247_route_admin('admin.backup.archives'),
                'button' => gp247_language_render('admin.backup.mail.open_screen'),
            ], [
                'to' => $to,
                'subject' => '[' . $site . '] ' . gp247_language_render($prefix . '_title', $facts),
            ]);
        } catch (\Throwable $e) {
            gp247_report('Backup notification "' . $event . '" not sent: ' . $e->getMessage());

            return false;
        }
    }
}
