<?php

namespace GP247\Core\PersonalData\Application\Events;

/**
 * An erasure ran to its end (sent once, by the last step of the job; not when an erasure is
 * applied again after a restore).
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-screen
 * @aidlc-adr personal-data-requests_request-model
 */
final class PersonalDataErased
{
    /**
     * @param string             $erasureId Ledger entry.
     * @param array<string, int> $counts    Rows handled per source table.
     */
    public function __construct(public readonly string $erasureId, public readonly array $counts)
    {
    }
}
