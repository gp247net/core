<?php

namespace GP247\Core\PersonalData\Domain;

/**
 * What happens to a whole row of a source when its data subject is erased.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise
 * @aidlc-adr personal-data-registry_anonymisation-rules
 */
enum RowRule: string
{
    /** The row is removed. */
    case Delete = 'delete';
    /** The row stays (accounting record); its column rules are applied. */
    case Anonymise = 'anonymise';
    /** The row stays untouched until its retention period ends (handled by retention). */
    case Retain = 'retain';
}
