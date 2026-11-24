<?php

namespace GP247\Core\PersonalData\Domain;

/**
 * The person whose data is looked up, exported or erased: a customer account (its id and
 * every email known for it) or a guest known only by email. Emails are normalised (trimmed,
 * lower case) so the same address always matches and hashes the same way.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-subject-lookup
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class DataSubject
{
    /**
     * @param string|null  $customerId
     * @param list<string> $emails Normalised, unique, non-empty.
     */
    private function __construct(public readonly ?string $customerId, public readonly array $emails)
    {
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
        return new self($this->customerId, self::normaliseAll(array_merge($this->emails, $emails)));
    }

    /**
     * @return bool
     */
    public function isGuest(): bool
    {
        return $this->customerId === null;
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
