<?php

namespace GP247\Core\Support\StepUp;

use GP247\Core\Api\Contracts\LoginChallenge;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Confirm the signed-in account again before a dangerous action: the sign-in password, then
 * every second-step challenge registered for its audience (e.g. the MFA plugin) that applies.
 * The proof lives in the session for a few minutes, bound to the account and the session id;
 * wrong answers are limited per account.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr personal-data-registry_shared-step-up
 */
final class StepUpGate
{
    public const MAX_ATTEMPTS = 5;
    public const DECAY_SECONDS = 60;

    /**
     * @param StepUpContext $context
     */
    public function __construct(private readonly StepUpContext $context)
    {
    }

    /**
     * @return StepUpContext
     */
    public function context(): StepUpContext
    {
        return $this->context;
    }

    /**
     * @return bool Whether the current account confirmed in this session within the validity.
     */
    public function isFresh(): bool
    {
        $user = $this->user();
        $mark = StepUpMark::fromArray(session()->get($this->context->sessionKey));

        return $user !== null && $mark !== null
            && $mark->isValidFor((string) $user->getAuthIdentifier(), (string) session()->getId(), now()->getTimestamp(), $this->context->validity());
    }

    /**
     * @return void
     * @throws StepUpRefused When the account has not confirmed recently.
     */
    public function ensureFresh(): void
    {
        if (!$this->isFresh()) {
            throw new StepUpRefused(StepUpRefused::REQUIRED);
        }
    }

    /**
     * @return bool Whether the account must also type a second-step code.
     */
    public function codeRequired(): bool
    {
        $user = $this->user();

        return $user !== null && $this->challenges($user) !== [];
    }

    /**
     * @param string $password Sign-in password.
     * @param string $code     Second-step code (TOTP or recovery code), when required.
     * @return void
     * @throws StepUpRefused
     */
    public function confirm(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code = ''): void
    {
        $user = $this->user();
        if ($user === null) {
            throw new StepUpRefused(StepUpRefused::NO_ADMIN);
        }
        $key = $this->context->throttlePrefix . $user->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $wait = RateLimiter::availableIn($key);
            $this->audit(StepUpRefused::THROTTLED, ['reason' => StepUpRefused::THROTTLED, 'retry_after' => $wait]);

            throw new StepUpRefused(StepUpRefused::THROTTLED, $wait);
        }
        $reason = $this->check($user, $password, $code);
        if ($reason !== null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $this->audit('refused', ['reason' => $reason]);

            throw new StepUpRefused($reason);
        }
        RateLimiter::clear($key);
        session()->put(
            $this->context->sessionKey,
            (new StepUpMark((string) $user->getAuthIdentifier(), (string) session()->getId(), now()->getTimestamp()))->toArray()
        );
        $this->audit('ok', []);
    }

    /**
     * @return void
     */
    public function forget(): void
    {
        session()->forget($this->context->sessionKey);
    }

    /**
     * @param Authenticatable $user
     * @param string          $password
     * @param string          $code
     * @return string|null Refusal reason, or null when every check passed.
     */
    private function check(Authenticatable $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code): ?string
    {
        if (!Hash::check($password, (string) $user->getAuthPassword())) {
            return StepUpRefused::WRONG_PASSWORD;
        }
        foreach ($this->challenges($user) as $challenge) {
            if (!$challenge->verify($user, $this->answer($code))) {
                return StepUpRefused::WRONG_CODE;
            }
        }

        return null;
    }

    /**
     * @return Authenticatable|null
     */
    private function user(): ?Authenticatable
    {
        $user = ($this->context->user)();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * @param string               $result  ok, refused or throttled.
     * @param array<string, mixed> $context
     * @return void
     */
    private function audit(string $result, array $context): void
    {
        if ($this->context->audit !== null) {
            ($this->context->audit)('step_up', $result, $context);
        }
    }

    /**
     * @param Authenticatable $user
     * @return list<LoginChallenge> Registered challenges of the audience that apply to this account.
     * @throws \UnexpectedValueException When a registration is not a LoginChallenge (never silently skipped).
     */
    private function challenges(Authenticatable $user): array
    {
        $out = [];
        foreach ((array) config('gp247-config.api.login_challenge.' . $this->context->audience, []) as $entry) {
            $challenge = is_string($entry) && class_exists($entry) ? app($entry) : $entry;
            if (!$challenge instanceof LoginChallenge) {
                throw new \UnexpectedValueException('Invalid login challenge registered for ' . $this->context->audience);
            }
            if ($challenge->required($user, request()) === true) {
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
