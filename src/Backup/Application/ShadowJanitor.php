<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Infrastructure\Database\SchemaInspector;
use GP247\Core\Backup\Infrastructure\Database\TableSwapper;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use GP247\Core\Backup\Infrastructure\Store\RestoreLedger;
use Illuminate\Support\Facades\DB;

/**
 * Lazy clean-up of restore copies (ADR shadow-table-swap §3): old tables T__bko once the undo
 * window of the last table-replacing restore has passed, and shadows T__bkr left by a restore
 * that died. Never while a job that writes (backup / restore / import / undo) is active — a
 * running restore owns them; never copies of quarantined tables (kept for a person to inspect);
 * never copies of another site sharing the database with another prefix.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-full-database
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class ShadowJanitor
{
    /**
     * @param JobRepository|null     $jobs
     * @param RestoreLedger|null     $ledger
     * @param DataGroupRegistry|null $registry
     */
    public function __construct(
        private ?JobRepository $jobs = null,
        private ?RestoreLedger $ledger = null,
        private ?DataGroupRegistry $registry = null,
    ) {
        $this->jobs ??= new JobRepository;
        $this->ledger ??= new RestoreLedger;
    }

    /**
     * Drop every copy of this site's tables except the old tables still inside the undo window
     * and the copies of quarantined tables.
     *
     * @return list<string> Tables dropped.
     */
    public function sweep(): array
    {
        if ($this->jobs->hasActiveWriteJob()) {
            return [];
        }
        $point = $this->ledger->undoPoint();
        $live = self::isLive($point);
        $keep = $this->kept($point);

        $swapper = new TableSwapper(new SchemaInspector(DB::connection(GP247_DB_CONNECTION)));
        $dropped = [];
        foreach ($swapper->copies($this->rawTables()) as $copy) {
            if (isset($keep[$copy])) {
                continue;
            }
            if ($swapper->drop($copy)) {
                $dropped[] = $copy;
            } else {
                BackupLog::failure('Copy ' . $copy . ' still holds a trigger of the site: kept for a person to move it.');
            }
        }
        if ($point !== null && !$live && !empty($point['tables'])) {
            $this->ledger->recordUndoPoint(null);
        }

        return $dropped;
    }

    /**
     * The cheap path for frequent callers (every idle slice): sweeps when the undo window of the
     * last restore has ended, or when copies exist that the live undo point does not hold — left
     * by an undone restore or by a restore that a newer one replaced as the undo point.
     *
     * @return list<string> Tables dropped.
     *
     * @aidlc-story US-backup-restore-table-restore
     */
    public function sweepExpired(): array
    {
        $point = $this->ledger->undoPoint();
        if ($point !== null && !empty($point['tables']) && !self::isLive($point)) {
            return $this->sweep();
        }

        return $this->hasStrayCopies($point) ? $this->sweep() : [];
    }

    /**
     * WHY: once undone, or replaced as the undo point, a restore's copies are no longer "expired"
     * by any date, so before 2026-11-24 they stayed until the next restore and went into every
     * full backup. A copy holding a site trigger is never dropped, so it does not count here —
     * otherwise every idle slice would sweep and log the same failure again.
     *
     * @param array<string, mixed>|null $point Undo point of the last table-replacing restore.
     * @return bool A copy exists that neither the live undo point nor the quarantine keeps.
     */
    private function hasStrayCopies(?array $point): bool
    {
        $schema = new SchemaInspector(DB::connection(GP247_DB_CONNECTION));
        // Cheap test first: one table listing; most sites have no copy at all.
        $candidates = array_filter(array_keys($schema->tables()), fn ($name) => TableSwapper::isCopy((string) $name));
        if ($candidates === []) {
            return false;
        }
        $keep = $this->kept($point);
        foreach ((new TableSwapper($schema))->copies($this->rawTables()) as $copy) {
            if (!isset($keep[$copy]) && $schema->triggersOn($copy) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $point
     * @return bool The undo point still allows an undo.
     */
    private static function isLive(?array $point): bool
    {
        return $point !== null && is_string($point['undo_until'] ?? null) && now()->lt($point['undo_until']);
    }

    /**
     * @param array<string, mixed>|null $point
     * @return array<string, true> Copies that are never dropped now: the old tables of a live undo
     *                             point, and both copies of quarantined tables.
     */
    private function kept(?array $point): array
    {
        $keep = [];
        foreach (self::isLive($point) ? (array) ($point['tables'] ?? []) : [] as $table) {
            $keep[TableSwapper::name((string) $table, TableSwapper::OLD)] = true;
        }
        foreach ($this->ledger->quarantined() as $table) {
            $keep[TableSwapper::name($table, TableSwapper::OLD)] = true;
            $keep[TableSwapper::name($table, TableSwapper::SHADOW)] = true;
        }

        return $keep;
    }

    /**
     * @return list<string> This site's tables without GP247_DB_PREFIX (migrations, declared tables).
     */
    private function rawTables(): array
    {
        $registry = $this->registry ?? new DataGroupRegistry;
        $raw = [SiteVersions::migrationsTable()];
        foreach ($registry->groups() as $group) {
            array_push($raw, ...$group->raw);
        }

        return array_values(array_unique($raw));
    }
}
