<?php

namespace GP247\Core\PersonalData\Application\Events;

/**
 * A customer's data package is built and waiting to be downloaded.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-export-package
 * @aidlc-adr personal-data-requests_request-model
 */
final class PersonalDataExportReady
{
    /**
     * @param string                $exportId
     * @param string|null           $requestId Request the package was made for, if any.
     * @param array<string, int>    $counts    Rows placed in the package per source table.
     */
    public function __construct(public readonly string $exportId, public readonly ?string $requestId, public readonly array $counts)
    {
    }
}
