<?php

namespace GP247\Core\PersonalData\Application;

use GP247\Core\PersonalData\Domain\DataSubject;

/**
 * What the site holds about one subject: rows per declared source, and every email known for a
 * customer from the columns a package declares as the customer's own (identity emails — e.g. the
 * account's address, never an address typed on an order, which may be someone else's), so guest
 * orders placed with that address are found too.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-subject-lookup
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class SubjectLookup
{
    private const EMAIL_LIMIT = 50;

    /**
     * @param DataSubject $subject
     * @return DataSubject The subject with every email found on rows linked to its customer id.
     */
    public function expand(DataSubject $subject): DataSubject
    {
        if ($subject->customerId === null) {
            return $subject;
        }
        $customerOnly = DataSubject::customer($subject->customerId);
        $emails = [];
        foreach (PersonalDataRegistry::sources() as $source) {
            if ($source->emailColumns() === [] || !SourceQuery::sourceExists($source)) {
                continue;
            }
            foreach ($source->emailColumns() as $column) {
                $found = SourceQuery::forSubject($source, $customerOnly)
                    ->whereNotNull($column)->where($column, '<>', '')
                    ->distinct()->limit(self::EMAIL_LIMIT)->pluck($column)->all();
                $emails = array_merge($emails, array_map('strval', $found));
            }
        }

        return $subject->withEmails($emails);
    }

    /**
     * @param DataSubject $subject
     * @return list<array{table: string, label: string|array<string, string>, owner: string, rule: string, rows: int}>
     */
    public function counts(DataSubject $subject): array
    {
        $out = [];
        foreach (PersonalDataRegistry::sources() as $source) {
            if (!SourceQuery::sourceExists($source)) {
                continue;
            }
            $out[] = [
                'table' => $source->table,
                'label' => $source->label(),
                'owner' => $source->owner(),
                'rule' => $source->rowRule()->value,
                'rows' => SourceQuery::forSubject($source, $subject)->count(),
            ];
        }

        return $out;
    }
}
