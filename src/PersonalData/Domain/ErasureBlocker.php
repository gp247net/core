<?php

namespace GP247\Core\PersonalData\Domain;

/**
 * A package's reason not to erase a subject now (e.g. shop: an order is still being shipped).
 * Registered with PersonalDataRegistry::blocker(); every erasure asks every blocker first.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise, US-customer-privacy-admin-delete-complete
 * @aidlc-adr personal-data-registry_erasure-job
 */
interface ErasureBlocker
{
    /**
     * @param DataSubject $subject
     * @return list<array{reason: string, ref: string, label: string}> Empty when nothing blocks;
     *         reason = stable code, ref = record id (e.g. order id), label = text for screens.
     */
    public function blockers(DataSubject $subject): array;
}
