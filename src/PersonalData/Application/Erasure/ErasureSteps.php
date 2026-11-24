<?php

namespace GP247\Core\PersonalData\Application\Erasure;

use Closure;
use GP247\Core\PersonalData\Application\ErasureLedger;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\SourceQuery;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\KnownValues;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\RowRule;

/**
 * The steps of one erasure, resumable at any chunk:
 *
 *  1. collect — gather the subject's identifying values from every source that declares them
 *     (before anything is erased, so free text elsewhere can be scrubbed);
 *  2. erase   — every source in declared order (records that point to others first, the
 *     customer row last): delete rows or apply column rules, chunk by chunk, by ascending key;
 *  3. done    — the ledger entry is completed and the collected values are dropped.
 *
 * The cursor is plain data (saved encrypted by the job repository).
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise, US-personal-data-registry-free-text-scrub
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class ErasureSteps
{
    public const COLLECT = 'collect';
    public const ERASE = 'erase';
    public const DONE = 'done';

    private const CHUNK = 200;

    /**
     * @param Anonymiser    $anonymiser
     * @param ErasureLedger $ledger
     */
    public function __construct(private readonly Anonymiser $anonymiser, private readonly ErasureLedger $ledger)
    {
    }

    /**
     * @param DataSubject $subject
     * @param string      $erasureId
     * @return array<string, mixed> Cursor of a new erasure.
     */
    public static function start(DataSubject $subject, string $erasureId): array
    {
        $tables = [];
        foreach (PersonalDataRegistry::sources() as $source) {
            if (SourceQuery::sourceExists($source)) {
                $tables[] = $source->table;
            }
        }

        return [
            'subject' => ['customer' => $subject->customerId, 'emails' => $subject->emails],
            'erasure' => $erasureId,
            'tables' => $tables,
            'phase' => self::COLLECT,
            'index' => 0,
            'last' => null,
            'known' => [],
            'counts' => [],
        ];
    }

    /**
     * Advance until done or out of time.
     *
     * @param array<string, mixed>                 $cursor
     * @param float                                $deadline   microtime(true) limit.
     * @param Closure(array<string, mixed>): void $checkpoint Saves the cursor after each chunk.
     * @return array<string, mixed> The cursor reached.
     */
    public function advance(array $cursor, float $deadline, Closure $checkpoint): array
    {
        $subject = $this->subject($cursor);
        while ($cursor['phase'] !== self::DONE && microtime(true) < $deadline) {
            $cursor = $cursor['phase'] === self::COLLECT ? $this->collectChunk($cursor, $subject) : $this->eraseChunk($cursor, $subject);
            $checkpoint($cursor);
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $cursor
     * @param DataSubject          $subject
     * @return array<string, mixed>
     */
    private function collectChunk(array $cursor, DataSubject $subject): array
    {
        [$cursor, $source] = $this->current($cursor);
        if ($source === null) {
            return array_merge($cursor, ['phase' => self::ERASE, 'index' => 0, 'last' => null]);
        }
        if ($source->identityColumns() === []) {
            return $this->nextSource($cursor);
        }
        $rows = $this->chunk($source, $subject, $cursor['last'], array_merge([$source->key], $source->identityColumns()));
        if ($rows === []) {
            return $this->nextSource($cursor);
        }
        $values = [];
        foreach ($rows as $row) {
            foreach ($source->identityColumns() as $column) {
                $values[] = $row[$column] ?? null;
            }
        }
        $known = KnownValues::of(array_merge((array) $cursor['known'], $values, $subject->emails));
        $cursor['known'] = $known->all();
        $cursor['last'] = end($rows)[$source->key];

        return $cursor;
    }

    /**
     * @param array<string, mixed> $cursor
     * @param DataSubject          $subject
     * @return array<string, mixed>
     */
    private function eraseChunk(array $cursor, DataSubject $subject): array
    {
        [$cursor, $source] = $this->current($cursor);
        if ($source === null) {
            $this->ledger->complete((string) $cursor['erasure'], (array) $cursor['counts']);

            return array_merge($cursor, ['phase' => self::DONE, 'known' => [], 'subject' => null]);
        }
        if ($source->rowRule() === RowRule::Retain) {
            return $this->nextSource($cursor);
        }
        $rows = $this->chunk($source, $subject, $cursor['last'], [$source->key]);
        if ($rows === []) {
            return $this->nextSource($cursor);
        }
        $keys = array_map(fn (array $r) => $r[$source->key], $rows);
        if ($source->rowRule() === RowRule::Delete) {
            SourceQuery::db()->table($source->physicalTable())->whereIn($source->key, $keys)->delete();
        } else {
            $this->anonymiser->apply($source, $keys, KnownValues::of((array) $cursor['known']));
        }
        $cursor['counts'][$source->table] = (int) ($cursor['counts'][$source->table] ?? 0) + count($keys);
        // WHY keep "last" even after deleting: the next chunk starts after it either way.
        $cursor['last'] = end($keys);

        return $cursor;
    }

    /**
     * @param PersonalDataSource $source
     * @param DataSubject        $subject
     * @param string|int|null    $after
     * @param list<string>       $columns
     * @return list<array<string, mixed>>
     */
    private function chunk(PersonalDataSource $source, DataSubject $subject, string|int|null $after, array $columns): array
    {
        $query = SourceQuery::forSubject($source, $subject);
        if ($after !== null) {
            $query->where($source->key, '>', $after);
        }

        return $query->orderBy($source->key)->limit(self::CHUNK)->get(array_values(array_unique($columns)))
            ->map(fn ($row) => (array) $row)->all();
    }

    /**
     * @param array<string, mixed> $cursor
     * @return array{0: array<string, mixed>, 1: PersonalDataSource|null} Cursor moved to the next declared
     *         source at or after its index, and that source (null past the end).
     */
    private function current(array $cursor): array
    {
        $tables = (array) $cursor['tables'];
        while (isset($tables[$cursor['index']])) {
            $source = PersonalDataRegistry::source((string) $tables[$cursor['index']]);
            if ($source !== null) {
                return [$cursor, $source];
            }
            // WHY: an extension removed between slices — its table is no longer declared.
            $cursor['index']++;
            $cursor['last'] = null;
        }

        return [$cursor, null];
    }

    /**
     * @param array<string, mixed> $cursor
     * @return array<string, mixed>
     */
    private function nextSource(array $cursor): array
    {
        return array_merge($cursor, ['index' => (int) $cursor['index'] + 1, 'last' => null]);
    }

    /**
     * @param array<string, mixed> $cursor
     * @return DataSubject
     */
    private function subject(array $cursor): DataSubject
    {
        $data = (array) ($cursor['subject'] ?? []);
        $emails = array_map('strval', (array) ($data['emails'] ?? []));
        if (($data['customer'] ?? null) !== null) {
            return DataSubject::customer((string) $data['customer'], $emails);
        }

        return $emails === [] ? DataSubject::guest('unknown@invalid') : DataSubject::guest($emails[0])->withEmails($emails);
    }
}
