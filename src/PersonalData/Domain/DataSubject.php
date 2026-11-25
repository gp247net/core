<?php

namespace GP247\Core\PersonalData\Domain;

/**
 * The person whose data is looked up, exported or erased: a customer account (its id and
 * every email known for it) or a guest known only by email. Emails are normalised (trimmed,
 * lower case) so the same address always matches and hashes the same way.
 *
 * A third kind is a group of records (old orders past their retention period): the records of
 * one declared source by key, with the sources under them, whoever placed them.
 *
 * @aidlc-unit personal-data-registry, personal-data-retention
 * @aidlc-story US-personal-data-registry-subject-lookup, US-personal-data-retention-order-anonymise
 * @aidlc-adr personal-data-registry_erasure-job, personal-data-retention_record-anonymise
 */
final class DataSubject
{
    /** Most records in one group (one erasure job). */
    public const MAX_RECORDS = 500;

    /**
     * @param string|null                                    $customerId
     * @param list<string>                                   $emails  Normalised, unique, non-empty.
     * @param array{table: string, ids: list<string>}|null $records A group of records instead of a person.
     */
    private function __construct(public readonly ?string $customerId, public readonly array $emails, public readonly ?array $records = null)
    {
    }

    /**
     * @param string               $table Logical name of a declared source.
     * @param list<string|int>     $ids   Keys of its records.
     * @return self
     * @throws \InvalidArgumentException When the group is empty, too large or the table is not a plain name.
     */
    public static function records(string $table, array $ids): self
    {
        SubjectLink::assertIdentifier($table);
        $keys = [];
        foreach ($ids as $id) {
            $value = trim((string) $id);
            if ($value !== '') {
                $keys[$value] = true;
            }
        }
        if ($keys === [] || count($keys) > self::MAX_RECORDS) {
            throw new \InvalidArgumentException('A group of records needs 1 to ' . self::MAX_RECORDS . ' records');
        }

        return new self(null, [], ['table' => $table, 'ids' => array_map('strval', array_keys($keys))]);
    }

    /**
     * @param string|int   $customerId
     * @param list<string> $emails
     * @return self
     * @throws \InvalidArgumentException When the id is empty.
     */
    public static function customer(string|int $customerId, array $emails = []): self
    {
        $id = trim((string) $customerId);
        if ($id === '' || $id === '0') {
            throw new \InvalidArgumentException('A customer subject needs a customer id');
        }

        return new self($id, self::normaliseAll($emails));
    }

    /**
     * @param string $email
     * @return self
     * @throws \InvalidArgumentException When the email is empty.
     */
    public static function guest(string $email): self
    {
        $emails = self::normaliseAll([$email]);
        if ($emails === []) {
            throw new \InvalidArgumentException('A guest subject needs an email');
        }

        return new self(null, $emails);
    }

    /**
     * @param list<string> $emails
     * @return self The same subject with more known emails.
     */
    public function withEmails(array $emails): self
    {
        return new self($this->customerId, self::normaliseAll(array_merge($this->emails, $emails)), $this->records);
    }

    /**
     * @return bool
     */
    public function isGuest(): bool
    {
        return $this->customerId === null && $this->records === null;
    }

    /**
     * @return bool Whether the subject is a group of records rather than a person.
     */
    public function isRecords(): bool
    {
        return $this->records !== null;
    }

    /**
     * @param string|null $email
     * @return string Trimmed lower-case address ('' when empty).
     */
    public static function normalise(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /**
     * @param list<string|null> $emails
     * @return list<string>
     */
    private static function normaliseAll(array $emails): array
    {
        $out = [];
        foreach ($emails as $email) {
            $value = self::normalise($email);
            if ($value !== '' && str_contains($value, '@')) {
                $out[$value] = true;
            }
        }

        return array_keys($out);
    }
}
