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
        $live = $point !== null && is_string($point['undo_until'] ?? null) && now()->lt($point['undo_until']);
        $keep = [];
        foreach ($live ? (array) ($point['tables'] ?? []) : [] as $table) {
            $keep[TableSwapper::name((string) $table, TableSwapper::OLD)] = true;
        }
        foreach ($this->ledger->quarantined() as $table) {
            $keep[TableSwapper::name($table, TableSwapper::OLD)] = true;
            $keep[TableSwapper::name($table, TableSwapper::SHADOW)] = true;
        }

        $swapper = new TableSwapper(new SchemaInspector(DB::connection(GP247_DB_CONNECTION)));
        $dropped = [];
        foreach ($swapper->copies($this->rawTables()) as $copy) {
            if (!isset($keep[$copy])) {
                $swapper->drop($copy);
                $dropped[] = $copy;
            }
        }
        if ($point !== null && !$live && !empty($point['tables'])) {
            $this->ledger->recordUndoPoint(null);
        }

        return $dropped;
    }

    /**
     * The cheap path for frequent callers (every idle slice): only when the undo window of the
     * last restore has ended.
     *
     * @return list<string> Tables dropped.
     */
    public function sweepExpired(): array
    {
        $point = $this->ledger->undoPoint();
        if ($point === null || empty($point['tables']) || !is_string($point['undo_until'] ?? null) || now()->lt($point['undo_until'])) {
            return [];
        }

        return $this->sweep();
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
