<?php

namespace GP247\Core\PersonalData\Application;

use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use Illuminate\Support\Str;

/**
 * Record of every erasure, written BEFORE anything is erased (a restore in the middle of an
 * erasure still knows it must erase again). It keeps only the customer id, salted hashes of
 * the emails, when, why and how many rows per source — never the erased data itself.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erasure-ledger
 * @aidlc-adr personal-data-registry_erasure-ledger
 */
final class ErasureLedger
{
    /**
     * @param string|null  $customerId
     * @param list<string> $emails Plain emails; only their hashes are stored.
     * @param string       $origin admin | customer | guest | retention | restore
     * @param string|null  $jobId
     * @return string Entry id.
     */
    public function open(?string $customerId, array $emails, string $origin, ?string $jobId = null): string
    {
        $id = (string) Str::ulid();
        SourceQuery::db()->table(PersonalDataSchema::table(PersonalDataSchema::ERASURE))->insert([
            'id' => $id,
            'customer_id' => $customerId,
            'origin' => $origin,
            'job_id' => $jobId,
            'requested_at' => now(),
            'completed_at' => null,
            'outcome' => null,
        ]);
        $this->addEmails($id, $emails);

        return $id;
    }

    /**
     * @param string       $entryId
     * @param list<string> $emails
     * @return void
     */
    public function addEmails(string $entryId, array $emails): void
    {
        $rows = [];
        foreach (array_unique(array_map(fn ($e) => Pepper::hashEmail((string) $e), $emails)) as $hash) {
            $rows[] = ['erasure_id' => $entryId, 'email_hash' => $hash];
        }
        if ($rows !== []) {
            SourceQuery::db()->table(PersonalDataSchema::table(PersonalDataSchema::ERASURE_EMAIL))->insertOrIgnore($rows);
        }
    }

    /**
     * @param string               $entryId
     * @param string               $jobId
     * @return void
     */
    public function attachJob(string $entryId, string $jobId): void
    {
        SourceQuery::db()->table(PersonalDataSchema::table(PersonalDataSchema::ERASURE))->where('id', $entryId)->update(['job_id' => $jobId]);
    }

    /**
     * @param string             $entryId
     * @param array<string, int> $outcome Rows handled per source table.
     * @return void
     */
    public function complete(string $entryId, array $outcome): void
    {
        SourceQuery::db()->table(PersonalDataSchema::table(PersonalDataSchema::ERASURE))->where('id', $entryId)->update([
            'completed_at' => now(),
            'outcome' => json_encode($outcome, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param string|null $customerId
     * @param string|null $email
     * @return bool Whether this customer id or email address was erased (exact match only).
     */
    public function contains(?string $customerId, ?string $email = null): bool
    {
        $db = SourceQuery::db();
        if ($customerId !== null && $customerId !== '' && $db->table(PersonalDataSchema::table(PersonalDataSchema::ERASURE))->where('customer_id', $customerId)->exists()) {
            return true;
        }
        if ($email === null || trim($email) === '') {
            return false;
        }

        return $db->table(PersonalDataSchema::table(PersonalDataSchema::ERASURE_EMAIL))->where('email_hash', Pepper::hashEmail($email))->exists();
    }
}
