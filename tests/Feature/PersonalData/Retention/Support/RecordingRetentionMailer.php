<?php

namespace GP247\Core\Tests\Feature\PersonalData\Retention\Support;

use GP247\Core\PersonalData\Domain\Requests\PrivacyMailer;

/**
 * Remembers which personal data emails would have gone out, and to whom.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-inactive-accounts
 */
final class RecordingRetentionMailer implements PrivacyMailer
{
    /** @var list<array{0: string, 1: string}> Message and recipient (or request id for the shop). */
    public array $sent = [];

    /** @inheritDoc */
    public function erasureScheduled(string $email, string $requestId, \DateTimeInterface $runsAt): void
    {
        $this->sent[] = ['erasureScheduled', $email];
    }

    /**
     * @param string             $email
     * @param string             $requestId
     * @param \DateTimeInterface $runsAt
     * @return void
     */
    public function inactiveAccount(string $email, string $requestId, \DateTimeInterface $runsAt): void
    {
        $this->sent[] = ['inactiveAccount', $email];
    }

    /** @inheritDoc */
    public function erased(string $email): void
    {
        $this->sent[] = ['erased', $email];
    }

    /** @inheritDoc */
    public function rejected(string $email, string $reason): void
    {
        $this->sent[] = ['rejected', $email];
    }

    /** @inheritDoc */
    public function verifyGuest(string $email, string $requestId, string $kind): void
    {
        $this->sent[] = ['verifyGuest', $email];
    }

    /** @inheritDoc */
    public function signInInstead(string $email): void
    {
        $this->sent[] = ['signInInstead', $email];
    }

    /** @inheritDoc */
    public function exportReady(string $email, string $requestId, string $exportId, bool $guest): void
    {
        $this->sent[] = ['exportReady', $email];
    }

    /** @inheritDoc */
    public function adminApprovalNeeded(string $requestId): void
    {
        $this->sent[] = ['adminApprovalNeeded', $requestId];
    }

    /** @inheritDoc */
    public function adminDueSoon(string $requestId, \DateTimeInterface $dueAt): void
    {
        $this->sent[] = ['adminDueSoon', $requestId];
    }
}
