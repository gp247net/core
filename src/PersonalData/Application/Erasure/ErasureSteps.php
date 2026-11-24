<?php

namespace GP247\Core\PersonalData\Application\Erasure;

use Closure;
use GP247\Core\PersonalData\Application\ErasureLedger;
use GP247\Core\PersonalData\Application\Events\PersonalDataErased;
use GP247\Core\PersonalData\Application\Pepper;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\SourceQuery;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\KnownValues;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\RowRule;
use GP247\Core\PersonalData\Domain\SubjectLink;
use GP247\Core\PersonalData\Infrastructure\JobRepository;

/**
 * The steps of one erasure, resumable at any chunk:
 *
 *  1. collect — gather the subject's identifying values from every source that declares them
 *     (before anything is erased, so free text elsewhere can be scrubbed);
 *  2. erase   — every source in declared order (records that point to others first, the
 *     customer row last): delete rows or apply column rules, chunk by chunk, by ascending key;
 *  3. done    — the ledger entry is completed and the collected values are dropped.
 *
 * Applying erasures again after a restore (reapply) is one job per restore, limited to the
 * restored tables; it leaves the ledger entries as they are:
 *
 *  0a. resolve — the ledger keeps only salted hashes of emails: the email columns of the restored
 *      tables are read ONCE for all entries, and each address whose hash is in an entry becomes
 *      an email of that entry again;
 *  0b. spawn   — one job per entry with a customer id or a found email (none when one already
 *      runs for that entry), which then collects and erases like an erasure.
 *
 * The cursor is plain data (saved encrypted by the job repository).
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise, US-personal-data-registry-free-text-scrub
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class ErasureSteps
{
    public const RESOLVE = 'resolve';
    public const SPAWN = 'spawn';
    public const COLLECT = 'collect';
    public const ERASE = 'erase';
    public const DONE = 'done';

    private const CHUNK = 200;

    /**
     * @param Anonymiser    $anonymiser
     * @param ErasureLedger $ledger
     * @param JobRepository $jobs
     */
    public function __construct(
        private readonly Anonymiser $anonymiser,
        private readonly ErasureLedger $ledger,
        private readonly JobRepository $jobs,
    ) {
    }

    /**
     * @param DataSubject $subject
     * @param string      $erasureId
     * @param string|null $notify    Address told when the erasure ends.
     * @return array<string, mixed> Cursor of a new erasure.
     */
    public static function start(DataSubject $subject, string $erasureId, ?string $notify = null): array
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
            'notify' => $notify,
            'tables' => $tables,
            'phase' => self::COLLECT,
            'index' => 0,
            'last' => null,
            'known' => [],
            'counts' => [],
        ];
    }

    /**
     * @param list<array{id: string, customer_id: string|null, hashes: list<string>}> $entries Ledger entries.
     * @param list<string>                                                          $tables  Declared source tables (logical names) restored.
     * @return array<string, mixed> Cursor of the job that applies these entries again after a restore.
     */
    public static function startReapply(array $entries, array $tables): array
    {
        return [
            'subject' => null,
            'erasure' => null,
            'tables' => array_values($tables),
            'phase' => self::RESOLVE,
            'index' => 0,
            'last' => null,
            'known' => [],
            'counts' => [],
            'reapply' => true,
            'entries' => array_map(fn (array $e) => [
                'id' => (string) $e['id'],
                'customer' => $e['customer_id'],
                'hashes' => array_values(array_unique($e['hashes'])),
                'emails' => [],
            ], $entries),
        ];
    }

    /**
     * @param array{id: string, customer: string|null, emails: list<string>} $entry
     * @param list<string>                                                   $tables
     * @return array<string, mixed> Cursor of one entry applied again (collect, then erase).
     */
    public static function startReapplyOf(array $entry, array $tables): array
    {
        return [
            'subject' => ['customer' => $entry['customer'], 'emails' => $entry['emails']],
            'erasure' => $entry['id'],
            'tables' => array_values($tables),
            'phase' => self::COLLECT,
            'index' => 0,
            'last' => null,
            'known' => [],
            'counts' => [],
            'reapply' => true,
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
        while ($cursor['phase'] !== self::DONE && microtime(true) < $deadline) {
            if ($cursor['phase'] === self::RESOLVE) {
                $cursor = $this->resolveChunk($cursor);
            } elseif ($cursor['phase'] === self::SPAWN) {
                $cursor = $this->spawn($cursor);
            } else {
                // WHY per chunk: the resolve step adds emails to the subject.
                $subject = $this->subject($cursor);
                $cursor = $cursor['phase'] === self::COLLECT ? $this->collectChunk($cursor, $subject) : $this->eraseChunk($cursor, $subject);
            }
            $checkpoint($cursor);
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $cursor
     * @return array<string, mixed>
     */
    private function resolveChunk(array $cursor): array
    {
        [$cursor, $source] = $this->current($cursor);
        if ($source === null) {
            return array_merge($cursor, ['phase' => self::SPAWN, 'index' => 0, 'last' => null]);
        }
        $wanted = [];
        foreach ((array) $cursor['entries'] as $i => $entry) {
            foreach ((array) $entry['hashes'] as $hash) {
                $wanted[(string) $hash][] = $i;
            }
        }
        $columns = self::emailColumnsOf($source);
        if ($columns === [] || $wanted === []) {
            return $this->nextSource($cursor);
        }
        $query = SourceQuery::db()->table($source->physicalTable());
        if ($cursor['last'] !== null) {
            $query->where($source->key, '>', $cursor['last']);
        }
        $rows = $query->orderBy($source->key)->limit(self::CHUNK)->get(array_merge([$source->key], $columns))->map(fn ($r) => (array) $r)->all();
        if ($rows === []) {
            return $this->nextSource($cursor);
        }
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (!is_string($value) || !str_contains($value, '@')) {
                    continue;
                }
                foreach ($wanted[Pepper::hashEmail($value)] ?? [] as $i) {
                    $email = DataSubject::normalise($value);
                    if (!in_array($email, $cursor['entries'][$i]['emails'], true)) {
                        $cursor['entries'][$i]['emails'][] = $email;
                    }
                }
            }
        }
        $cursor['last'] = end($rows)[$source->key];

        return $cursor;
    }

    /**
     * @param array<string, mixed> $cursor
     * @return array<string, mixed> The batch job, done: one job per entry with something to erase.
     */
    private function spawn(array $cursor): array
    {
        foreach ((array) $cursor['entries'] as $entry) {
            // WHY skip: a guest whose address is in none of the restored rows (nothing came back),
            // or an entry another restore already applies again.
            if (($entry['customer'] === null && $entry['emails'] === []) || $this->jobs->hasActiveReapply((string) $entry['id'])) {
                continue;
            }
            $this->jobs->create('reapply', 'restore', $entry['customer'], null, null, (string) $entry['id'], self::startReapplyOf($entry, (array) $cursor['tables']));
        }

        return array_merge($cursor, ['phase' => self::DONE, 'entries' => []]);
    }

    /**
     * @param PersonalDataSource $source
     * @return list<string> Columns of the source holding email addresses (declared identity emails and email links).
     */
    private static function emailColumnsOf(PersonalDataSource $source): array
    {
        $columns = $source->emailColumns();
        foreach ($source->links() as $link) {
            if ($link->kind === SubjectLink::EMAIL) {
                $columns[] = $link->column;
            }
        }

        return array_values(array_unique($columns));
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
            // WHY not on reapply: the entry records the erasure that was asked for; applying it
            // again after a restore changes neither when nor how much was asked.
            if (empty($cursor['reapply'])) {
                $this->ledger->complete((string) $cursor['erasure'], (array) $cursor['counts']);
                // Tells the request this erasure was run for that it is done (personal-data-requests).
                // WHY caught: the data is erased and the ledger completed — a listener failing must
                // not turn a finished erasure into a failed job (it is reported, by class only).
                try {
                    event(new PersonalDataErased((string) $cursor['erasure'], array_map('intval', (array) $cursor['counts']), empty($cursor['notify']) ? [] : [(string) $cursor['notify']]));
                } catch (\Throwable $e) {
                    if (function_exists('gp247_report')) {
                        gp247_report('PersonalDataErased listener failed: ' . $e::class);
                    }
                }
            }

            return array_merge($cursor, ['phase' => self::DONE, 'known' => [], 'subject' => null, 'notify' => null]);
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
