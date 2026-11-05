<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Api\Contracts\LoginChallenge;
use GP247\Core\Backup\Domain\StepUpMark;
use GP247\Core\Backup\Domain\StepUpRefused;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Confirm the signed-in admin again before a sensitive backup action (download, restore,
 * backup-password change): the sign-in password, then every second-step challenge registered
 * for admins (`gp247-config.api.login_challenge.admin`, e.g. the MFA plugin) that applies to
 * them. The proof lives in the session for a few minutes and only for that session id; wrong
 * answers are limited per admin.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-step-up-auth
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
final class StepUpService
{
    public const SESSION_KEY = 'gp247_backup_step_up';
    public const MAX_ATTEMPTS = 5;
    public const DECAY_SECONDS = 60;

    /**
     * @return bool Whether the current admin confirmed in this session within the validity.
     */
    public function isFresh(): bool
    {
        $admin = admin()->user();
        $mark = StepUpMark::fromArray(session()->get(self::SESSION_KEY));

        return $admin !== null && $mark !== null
            && $mark->isValidFor((string) $admin->id, (string) session()->getId(), now()->getTimestamp(), self::seconds());
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
        $admin = admin()->user();

        return $admin !== null && $this->challenges($admin) !== [];
    }

    /**
     * @param string $password Admin sign-in password.
     * @param string $code     Second-step code (TOTP or recovery code), when required.
     * @return void
     * @throws StepUpRefused
     */
    public function confirm(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code = ''): void
    {
        $admin = admin()->user();
        if ($admin === null) {
            throw new StepUpRefused(StepUpRefused::NO_ADMIN);
        }
        $key = 'backup-step-up:' . $admin->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $wait = RateLimiter::availableIn($key);
            BackupAudit::record('step_up', BackupAudit::REFUSED, ['reason' => StepUpRefused::THROTTLED, 'retry_after' => $wait]);

            throw new StepUpRefused(StepUpRefused::THROTTLED, $wait);
        }
        $reason = null;
        if (!Hash::check($password, (string) $admin->password)) {
            $reason = StepUpRefused::WRONG_PASSWORD;
        } else {
            foreach ($this->challenges($admin) as $challenge) {
                if (!$challenge->verify($admin, $this->answer($code))) {
                    $reason = StepUpRefused::WRONG_CODE;
                    break;
                }
            }
        }
        if ($reason !== null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            BackupAudit::record('step_up', BackupAudit::REFUSED, ['reason' => $reason]);

            throw new StepUpRefused($reason);
        }
        RateLimiter::clear($key);
        session()->put(self::SESSION_KEY, (new StepUpMark((string) $admin->id, (string) session()->getId(), now()->getTimestamp()))->toArray());
        BackupAudit::record('step_up', BackupAudit::OK);
    }

    /**
     * @return void
     */
    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * @return int Validity in seconds (at most 5 minutes — US-backup-admin-step-up-auth).
     */
    public static function seconds(): int
    {
        return max(30, min(300, (int) config('gp247-config.backup.step_up_seconds', 300)));
    }

    /**
     * @param Authenticatable $admin
     * @return list<LoginChallenge> Registered admin challenges that apply to this admin.
     * @throws \UnexpectedValueException When a registration is not a LoginChallenge (never silently skipped).
     */
    private function challenges(Authenticatable $admin): array
    {
        $out = [];
        foreach ((array) config('gp247-config.api.login_challenge.admin', []) as $entry) {
            $challenge = is_string($entry) && class_exists($entry) ? app($entry) : $entry;
            if (!$challenge instanceof LoginChallenge) {
                throw new \UnexpectedValueException('Invalid login challenge registered for admin');
            }
            if ($challenge->required($admin, request()) === true) {
                $out[] = $challenge;
            }
        }

        return $out;
    }

    /**
     * @param string $code
     * @return Request The answer in the fields challenges read (digits = code, else recovery code).
     */
    private function answer(#[\SensitiveParameter] string $code): Request
    {
        $code = trim($code);
        $field = preg_match('/^\d{4,10}$/', $code) === 1 ? 'code' : 'recovery_code';

        return Request::create('/', 'POST', [$field => $code]);
    }
}
