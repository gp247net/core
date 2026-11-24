<?php

namespace GP247\Core\PersonalData\Domain\Requests;

/**
 * Where a personal data request stands.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-screen
 * @aidlc-adr personal-data-requests_request-model
 */
enum RequestStatus: string
{
    case PendingVerification = 'pending_verification';
    case PendingApproval = 'pending_approval';
    case Waiting = 'waiting';
    case OnHold = 'on_hold';
    case Processing = 'processing';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /**
     * @return bool Nothing more happens to the request.
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    /**
     * @return list<string> Values of the statuses still to be handled (counted for overdue).
     */
    public static function openValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => !$s->isClosed())));
    }
}
