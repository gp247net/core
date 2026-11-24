<?php

namespace GP247\Core\AdminShell\Domain;

/**
 * An admin Livewire screen whose state holds personal data that must not be copied into the
 * operation log (admin_log keeps every action's public state and is never erased with a
 * customer). The listed properties are left out of the log row; the action itself is still
 * logged.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-audit-log
 * @aidlc-adr personal-data-requests_request-log
 */
interface HidesFromOperationLog
{
    /**
     * @return list<string> Public property names never written to the operation log.
     */
    public function operationLogHidden(): array;
}
