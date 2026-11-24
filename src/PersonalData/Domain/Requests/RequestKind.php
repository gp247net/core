<?php

namespace GP247\Core\PersonalData\Domain\Requests;

/**
 * What the data subject asked for.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-screen
 * @aidlc-adr personal-data-requests_request-model
 */
enum RequestKind: string
{
    case Export = 'export';
    case Erase = 'erase';
}
