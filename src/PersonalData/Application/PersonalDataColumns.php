<?php

namespace GP247\Core\PersonalData\Application;

/**
 * Column names that suggest personal data of a customer. One pattern for the undeclared-source
 * detection on a running site and for the coverage guard of the core (package guards may look
 * at more names).
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-registry-undeclared-detection
 * @aidlc-adr personal-data-requests_undeclared-sources
 */
final class PersonalDataColumns
{
    public const PATTERN = '/(^|_)(email|phone|first_name|last_name|address\d*|postcode|ip|ip_address|user_agent|customer_id|address_meta|identity)$/';

    /**
     * @param list<string> $columns
     * @return list<string> The ones that look like personal data.
     */
    public static function matching(array $columns): array
    {
        return array_values(preg_grep(self::PATTERN, $columns) ?: []);
    }
}
