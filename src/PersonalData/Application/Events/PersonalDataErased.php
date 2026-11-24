<?php

namespace GP247\Core\PersonalData\Application\Events;

/**
 * An erasure ran to its end (sent once, by the last step of the job; not when an erasure is
 * applied again after a restore).
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-screen, US-customer-privacy-customer-emails
 * @aidlc-adr personal-data-requests_request-model
 */
final class PersonalDataErased
{
    /**
     * @param string             $erasureId Ledger entry.
     * @param array<string, int> $counts    Rows handled per source table.
     * @param list<string>       $emails    Addresses of the person erased, known only in this
     *                                      request — for the "your data is erased" email, never stored.
     */
    public function __construct(public readonly string $erasureId, public readonly array $counts, public readonly array $emails = [])
    {
    }
}
