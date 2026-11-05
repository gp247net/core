<?php

namespace GP247\Core\Backup\Livewire\Concerns;

use GP247\Core\Backup\Application\BackupAudit;

/**
 * One line of feedback on a backup screen (success / error), with refusals turned into the
 * stable language string of their reason code and written to the operation log.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-dashboard, US-backup-admin-audit-log
 */
trait RendersNotices
{
    public ?string $notice = null;
    public string $noticeType = 'success';

    /**
     * @param string               $languageKey
     * @param string               $type success | error | warning
     * @param array<string, mixed> $replace
     * @return void
     */
    protected function notice(string $languageKey, string $type = 'success', array $replace = []): void
    {
        $this->notice = gp247_language_render($languageKey, $replace);
        $this->noticeType = $type;
    }

    /**
     * @param string               $action  Audit action.
     * @param string               $reason  Refusal reason code.
     * @param array<string, mixed> $context Audit context (allowlisted by BackupAudit).
     * @return void
     */
    protected function refused(string $action, string $reason, array $context = []): void
    {
        BackupAudit::record($action, BackupAudit::REFUSED, $context + ['reason' => $reason]);
        $key = 'admin.backup.refused.' . preg_replace('/[^a-z_]/', '', $reason);
        $text = gp247_language_render($key);
        $this->notice = $text === $key ? gp247_language_render('admin.backup.refused.other', ['reason' => $reason]) : $text;
        $this->noticeType = 'error';
    }

    /**
     * @return void
     */
    public function clearNotice(): void
    {
        $this->notice = null;
    }
}
