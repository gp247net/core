<?php

namespace GP247\Core\Backup\Application;

/**
 * A feature that must keep some rows from coming back through "add back deleted records"
 * (e.g. the personal data of an erased customer). Registered with UndeleteVetoes.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-restore-reapply
 * @aidlc-adr personal-data-registry_restore-reapply
 */
interface UndeleteVeto
{
    /**
     * @param string                     $realTable Real name of the table the rows would go into.
     * @param list<array<string, mixed>> $rows      Archive rows about to be inserted.
     * @param bool                       $ownSite   The archive is this site's own (its ids are this site's).
     * @return array<int, string> Index in $rows => reason code, for every row that must stay out.
     */
    public function refuse(string $realTable, array $rows, bool $ownSite = true): array;
}
