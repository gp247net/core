<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\StepUpRefused;
use GP247\Core\Support\StepUp\StepUpContext;
use GP247\Core\Support\StepUp\StepUpGate;
use GP247\Core\Support\StepUp\StepUpRefused as SharedStepUpRefused;

/**
 * Confirm the signed-in admin again before a sensitive backup action (download, restore,
 * backup-password change): the sign-in password, then every second-step challenge registered
 * for admins (`gp247-config.api.login_challenge.admin`, e.g. the MFA plugin) that applies to
 * them. The proof lives in the session for a few minutes and only for that session id; wrong
 * answers are limited per admin.
 *
 * The checks run in the shared step-up gate with the backup feature's own session key,
 * validity, throttle key and audit log, so the behaviour is the one it always had.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-step-up-auth
 * @aidlc-adr backup-admin_step-up-and-signed-download, personal-data-registry_shared-step-up
 */
final class StepUpService
{
    public const SESSION_KEY = 'gp247_backup_step_up';
    public const MAX_ATTEMPTS = StepUpGate::MAX_ATTEMPTS;
    public const DECAY_SECONDS = StepUpGate::DECAY_SECONDS;

    /**
     * @return bool Whether the current admin confirmed in this session within the validity.
     */
    public function isFresh(): bool
    {
        return $this->gate()->isFresh();
    }

    /**
     * @return void
     * @throws StepUpRefused When the admin has not confirmed recently.
     */
    public function ensureFresh(): void
    {
        if (!$this->isFresh()) {
            throw new StepUpRefused(StepUpRefused::REQUIRED);
        }
    }

    /**
     * @return bool Whether the admin must also type a second-step code.
     */
    public function codeRequired(): bool
    {
        return $this->gate()->codeRequired();
    }

    /**
     * @param string $password Admin sign-in password.
     * @param string $code     Second-step code (TOTP or recovery code), when required.
     * @return void
     * @throws StepUpRefused
     */
    public function confirm(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code = ''): void
    {
        try {
            $this->gate()->confirm($password, $code);
        } catch (SharedStepUpRefused $e) {
            // WHY: backup screens catch the backup refusal class; keep that contract.
            throw new StepUpRefused($e->reason, $e->retryAfter);
        }
    }

    /**
     * @return void
     */
    public function forget(): void
    {
        $this->gate()->forget();
    }

    /**
     * @return int Validity in seconds (at most 5 minutes — US-backup-admin-step-up-auth).
     */
    public static function seconds(): int
    {
        return max(30, min(300, (int) config('gp247-config.backup.step_up_seconds', 300)));
    }

    /**
     * @return StepUpGate The shared gate with the backup feature's parameters.
     */
    public function gate(): StepUpGate
    {
        return new StepUpGate(new StepUpContext(
            user: fn () => admin()->user(),
            audience: 'admin',
            sessionKey: self::SESSION_KEY,
            seconds: self::seconds(),
            throttlePrefix: 'backup-step-up:',
            audit: function (string $event, string $result, array $context): void {
                BackupAudit::record($event, $result === 'ok' ? BackupAudit::OK : BackupAudit::REFUSED, $context);
            },
        ));
    }
}
