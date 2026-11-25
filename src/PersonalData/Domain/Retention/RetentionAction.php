<?php

namespace GP247\Core\PersonalData\Domain\Retention;

/**
 * What happens to data older than its retention period:
 *  - delete:          the rows are removed, by the rule of the declared source (carts, reset codes, messages, logs);
 *  - anonymise:       the records lose the buyer's personal data through the erasure engine, money kept (old orders);
 *  - erase_accounts:  accounts unused for the period are told, then erased like a customer's own request.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-policies
 * @aidlc-adr personal-data-retention_retention-registry
 */
enum RetentionAction: string
{
    case Delete = 'delete';
    case Anonymise = 'anonymise';
    case EraseAccounts = 'erase_accounts';
}
