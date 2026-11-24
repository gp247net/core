<?php

namespace GP247\Core\Support\StepUp;

use Closure;

/**
 * Where one kind of step-up lives: which signed-in account it confirms, which second-step
 * challenges apply, the session key of its proof, how long the proof lasts, the throttle key
 * and where outcomes are recorded. Two features never share a proof (different session keys).
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr personal-data-registry_shared-step-up
 */
final class StepUpContext
{
    /**
     * @param Closure(): (\Illuminate\Contracts\Auth\Authenticatable|null) $user     Signed-in account.
     * @param string                                                     $audience Key under gp247-config.api.login_challenge (admin, customer).
     * @param string                                                     $sessionKey
     * @param int                                                        $seconds  Validity (30–300).
     * @param string                                                     $throttlePrefix
     * @param Closure(string, string, array<string,mixed>): void|null    $audit    (event, result, context).
     */
    public function __construct(
        public readonly Closure $user,
        public readonly string $audience,
        public readonly string $sessionKey,
        public readonly int $seconds,
        public readonly string $throttlePrefix,
        public readonly ?Closure $audit = null,
    ) {
    }

    /**
     * @return int Validity clamped to 30–300 seconds.
     */
    public function validity(): int
    {
        return max(30, min(300, $this->seconds));
    }
}
