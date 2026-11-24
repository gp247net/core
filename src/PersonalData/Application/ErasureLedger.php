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
     * @param \DateTimeInterface|null $from Null = every entry (the time of the restored data is unknown).
     * @return list<array{id: string, customer_id: string|null, hashes: list<string>}> Entries a restore
     *         of data from $from must erase again (oldest first): requested at or after $from, or not
     *         finished by then — an erasure still running when the tables were read left part of the
     *         customer's data in them.
     */
    public function since(?\DateTimeInterface $from): array
    {
        $db = SourceQuery::db();
        $query = $db->table(PersonalDataSchema::table(PersonalDataSchema::ERASURE));
        if ($from !== null) {
            $query->where(fn ($q) => $q->where('requested_at', '>=', $from)->orWhereNull('completed_at')->orWhere('completed_at', '>=', $from));
        }
        $entries = $query->orderBy('requested_at')->orderBy('id')->get(['id', 'customer_id'])->all();
        if ($entries === []) {
            return [];
        }
        $hashes = [];
        foreach ($db->table(PersonalDataSchema::table(PersonalDataSchema::ERASURE_EMAIL))
            ->whereIn('erasure_id', array_map(fn ($e) => (string) $e->id, $entries))->get(['erasure_id', 'email_hash']) as $row) {
            $hashes[(string) $row->erasure_id][] = (string) $row->email_hash;
        }

        return array_map(fn ($e) => [
            'id' => (string) $e->id,
            'customer_id' => $e->customer_id === null ? null : (string) $e->customer_id,
            'hashes' => $hashes[(string) $e->id] ?? [],
        ], $entries);
    }

    /**
     * @param list<string> $hashes Email hashes.
     * @return list<string> The ones found in the ledger.
     */
    public function knownHashes(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        return array_values(array_map('strval', SourceQuery::db()->table(PersonalDataSchema::table(PersonalDataSchema::ERASURE_EMAIL))
            ->whereIn('email_hash', array_values(array_unique($hashes)))->distinct()->pluck('email_hash')->all()));
    }

    /**
     * @param list<string> $customerIds
     * @return list<string> The ones found in the ledger.
     */
    public function knownCustomers(array $customerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $customerIds), fn ($id) => $id !== '' && $id !== '0')));
        if ($ids === []) {
            return [];
        }

        return array_values(array_map('strval', SourceQuery::db()->table(PersonalDataSchema::table(PersonalDataSchema::ERASURE))
            ->whereIn('customer_id', $ids)->distinct()->pluck('customer_id')->all()));
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
