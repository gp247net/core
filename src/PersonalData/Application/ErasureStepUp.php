<?php

namespace GP247\Core\PersonalData\Application;

use GP247\Core\PersonalData\Infrastructure\PersonalDataAudit;
use GP247\Core\Support\StepUp\StepUpContext;
use GP247\Core\Support\StepUp\StepUpGate;

/**
 * The "confirm it is you" gate an administrator passes before erasing a customer: the admin
 * sign-in password plus the admin second step (MFA plugin) when the site has one. Its proof is
 * its own — a backup confirmation does not open an erasure, and the other way round. Every
 * attempt (passed, refused, throttled) leaves a row in the admin operation log.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr personal-data-registry_shared-step-up, customer-privacy_admin-delete-flow
 */
final class ErasureStepUp
{
    public const SESSION_KEY = 'gp247_personal_data_step_up';
    public const SECONDS = 300;

    /**
     * @return StepUpGate
     */
    public static function admin(): StepUpGate
    {
        return new StepUpGate(new StepUpContext(
            user: fn () => function_exists('admin') ? admin()->user() : null,
            audience: 'admin',
            sessionKey: self::SESSION_KEY,
            seconds: self::SECONDS,
            throttlePrefix: 'personal-data-step-up:',
            audit: static function (string $event, string $result, array $context): void {
                PersonalDataAudit::record($event, $result, $context);
            },
        ));
    }
}
