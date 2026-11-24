<?php

namespace GP247\Core\PersonalData\Domain\Requests;

/**
 * The only moves a request can make. Screens, the API and the scheduler go through
 * RequestWorkflow, which asks this table first.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-screen
 * @aidlc-adr personal-data-requests_request-model
 */
final class RequestTransitions
{
    /** @var array<string, list<string>> From => allowed targets. */
    private const ALLOWED = [
        'pending_verification' => ['pending_approval', 'processing', 'cancelled', 'rejected'],
        'pending_approval' => ['waiting', 'processing', 'rejected', 'cancelled'],
        // WHY failed from waiting / on_hold: a request that cannot even start (data it names is
        // gone, a source throws) leaves the queue instead of being picked on every tick.
        'waiting' => ['processing', 'on_hold', 'failed', 'cancelled'],
        'on_hold' => ['processing', 'failed', 'cancelled'],
        // WHY on_hold from processing: an order placed between the check and the start.
        'processing' => ['completed', 'failed', 'on_hold'],
        'failed' => ['processing', 'on_hold', 'cancelled'],
        'completed' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    /**
     * @param RequestStatus $from
     * @param RequestStatus $to
     * @return bool
     */
    public static function allows(RequestStatus $from, RequestStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /**
     * @param RequestStatus $from
     * @param RequestStatus $to
     * @return void
     * @throws \DomainException When the move is not allowed.
     */
    public static function assertAllowed(RequestStatus $from, RequestStatus $to): void
    {
        if (!self::allows($from, $to)) {
            throw new \DomainException('A request cannot go from ' . $from->value . ' to ' . $to->value);
        }
    }
}
