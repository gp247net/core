<?php

namespace GP247\Core\PersonalData\Application;

use GP247\Core\Backup\Application\UndeleteVeto;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;

/**
 * "Add back deleted records" never brings back a row of an erased customer: a row of a declared
 * source (meeting the source's fixed conditions) is refused when its customer id is in the
 * erasure ledger, or when one of its email columns holds an address whose hash is in the ledger
 * (an email link restricted to guest rows counts only on guest rows). Ids are trusted only in
 * an archive of this site: another site's customer #3 is someone else.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-restore-reapply
 * @aidlc-adr personal-data-registry_restore-reapply
 */
final class ErasedRowsVeto implements UndeleteVeto
{
    public const REASON = 'erased_personal_data';

    /**
     * @param ErasureLedger $ledger
     */
    public function __construct(private readonly ErasureLedger $ledger)
    {
    }

    /**
     * @param string                     $realTable
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    public function refuse(string $realTable, array $rows, bool $ownSite = true): array
    {
        if (!ErasureService::ready()) {
            return [];
        }
        $source = $this->sourceOf($realTable);
        if ($source === null) {
            return [];
        }
        [$idColumns, $emailColumns] = $this->columns($source);
        if (!$ownSite) {
            $idColumns = [];
        }
        $rows = array_filter($rows, fn (array $row) => $this->meetsConditions($source, $row));
        $ids = [];
        $hashes = [];
        foreach ($rows as $row) {
            foreach ($idColumns as [$column, $typeColumn, $typeValue]) {
                if (($typeColumn === null || (string) ($row[$typeColumn] ?? '') === $typeValue) && isset($row[$column])) {
                    $ids[] = (string) $row[$column];
                }
            }
            foreach ($emailColumns as [$column]) {
                if (is_string($row[$column] ?? null) && str_contains($row[$column], '@')) {
                    $hashes[] = Pepper::hashEmail($row[$column]);
                }
            }
        }
        $erasedIds = array_flip($this->ledger->knownCustomers($ids));
        $erasedHashes = array_flip($this->ledger->knownHashes($hashes));
        $out = [];
        foreach ($rows as $index => $row) {
            if ($this->matches($row, $idColumns, $emailColumns, $erasedIds, $erasedHashes)) {
                $out[$index] = self::REASON;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>                                   $row
     * @param list<array{0: string, 1: string|null, 2: string|null}> $idColumns
     * @param list<array{0: string, 1: string|null}>                 $emailColumns Column, guest-only column.
     * @param array<string, int>                                     $erasedIds
     * @param array<string, int>                                     $erasedHashes
     * @return bool
     */
    private function matches(array $row, array $idColumns, array $emailColumns, array $erasedIds, array $erasedHashes): bool
    {
        foreach ($idColumns as [$column, $typeColumn, $typeValue]) {
            if (($typeColumn === null || (string) ($row[$typeColumn] ?? '') === $typeValue) && isset($row[$column], $erasedIds[(string) $row[$column]])) {
                return true;
            }
        }
        foreach ($emailColumns as [$column, $guestColumn]) {
            if ($guestColumn !== null && !in_array((string) ($row[$guestColumn] ?? ''), ['', '0'], true)) {
                continue;
            }
            if (is_string($row[$column] ?? null) && str_contains($row[$column], '@') && isset($erasedHashes[Pepper::hashEmail($row[$column])])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param PersonalDataSource   $source
     * @param array<string, mixed> $row
     * @return bool Whether the row meets the source's fixed conditions (e.g. audience = customer).
     */
    private function meetsConditions(PersonalDataSource $source, array $row): bool
    {
        foreach ($source->equals() as $column => $value) {
            if ((string) ($row[$column] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param PersonalDataSource $source
     * @return array{0: list<array{0: string, 1: string|null, 2: string|null}>, 1: list<array{0: string, 1: string|null}>}
     *         Customer id columns (with the morph type condition) and email columns (with the
     *         column that must be empty for the email link to count — guest rows).
     */
    private function columns(PersonalDataSource $source): array
    {
        $ids = [];
        $emails = [];
        foreach ($source->emailColumns() as $column) {
            $emails[$column] = [$column, null];
        }
        foreach ($source->links() as $link) {
            if ($link->kind === SubjectLink::CUSTOMER) {
                $ids[] = [$link->column, null, null];
            } elseif ($link->kind === SubjectLink::MORPH) {
                $ids[] = [$link->column, $link->typeColumn, $link->typeValue];
            } elseif ($link->kind === SubjectLink::EMAIL && !isset($emails[$link->column])) {
                $emails[$link->column] = [$link->column, $link->guestColumn];
            }
        }

        return [$ids, array_values($emails)];
    }

    /**
     * @param string $realTable
     * @return PersonalDataSource|null
     */
    private function sourceOf(string $realTable): ?PersonalDataSource
    {
        foreach (PersonalDataRegistry::sources() as $source) {
            if ($source->physicalTable() === $realTable) {
                return $source;
            }
        }

        return null;
    }
}
