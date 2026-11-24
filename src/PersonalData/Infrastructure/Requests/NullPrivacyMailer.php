<?php

namespace GP247\Core\PersonalData\Infrastructure\Requests;

use GP247\Core\PersonalData\Domain\Requests\PrivacyMailer;

/**
 * No storefront package installed: requests still work, nothing is sent.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-customer-privacy-customer-emails
 */
final class NullPrivacyMailer implements PrivacyMailer
{
    /** @inheritDoc */
    public function erasureScheduled(string $email, string $requestId, \DateTimeInterface $runsAt): void
    {
    }

    /** @inheritDoc */
    public function erased(string $email): void
    {
    }

    /** @inheritDoc */
    public function rejected(string $email, string $reason): void
    {
    }

    /** @inheritDoc */
    public function verifyGuest(string $email, string $requestId, string $kind): void
    {
    }

    /** @inheritDoc */
    public function signInInstead(string $email): void
    {
    }

    /** @inheritDoc */
    public function exportReady(string $email, string $requestId, string $exportId, bool $guest): void
    {
    }

    /** @inheritDoc */
    public function adminApprovalNeeded(string $requestId): void
    {
    }

    /** @inheritDoc */
    public function adminDueSoon(string $requestId, \DateTimeInterface $dueAt): void
    {
    }
}
