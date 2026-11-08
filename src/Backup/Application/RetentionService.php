<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Facades\DB;

/**
 * Applies the retention policy after a new archive passed verification (never before — a
 * broken new archive must not push a good old one out). Removal = the archive directory in
 * the vault, its finished jobs and its catalogue row.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-retention
 * @aidlc-adr backup-engine_core-module-layout
 */
final class RetentionService
{
    public const CONFIG_KEEP = 'backup_keep_count';
    public const CONFIG_KEEP_PRE_CHANGE = 'backup_keep_pre_change';
    public const DEFAULT_KEEP = 7;
    public const DEFAULT_KEEP_PRE_CHANGE = 3;

    private ArchiveRepository $archives;
    private JobRepository $jobs;
    private LocalVault $vault;
    private RetentionPolicy $policy;

    /**
     * @param ArchiveRepository|null $archives
     * @param JobRepository|null     $jobs
     * @param LocalVault|null        $vault
     * @param RetentionPolicy|null   $policy Null = gp247-config.backup.retention_policy.
     */
    public function __construct(?ArchiveRepository $archives = null, ?JobRepository $jobs = null, ?LocalVault $vault = null, ?RetentionPolicy $policy = null)
    {
        $this->archives = $archives ?? new ArchiveRepository;
        $this->jobs = $jobs ?? new JobRepository;
        $this->vault = $vault ?? app(LocalVault::class);
        $this->policy = $policy ?? self::configuredPolicy();
    }

    /**
     * @return list<string> Removed archive ids.
     */
    public function apply(): array
    {
        $archives = $this->archives->okNewestFirst();
        // WHY: the contract is enforced here too, so a third-party policy cannot remove the
        // last good archive, a pinned one, or the newest one whose off-site copy is not done.
        $protected = [];
        $known = [];
        // An archive a restore is reading from, or an off-site copy is sending, must stay until
        // that job ends.
        foreach ([Archive::JOB_RESTORE, Archive::JOB_UPLOAD] as $type) {
            foreach ($this->jobs->activeArchiveIds($type) as $id) {
                $protected[$id] = true;
            }
        }
        // WHY only the newest uncopied archive (BK-7, ⚑ #7): enough to copy it out when the
        // destination is back, without letting a long outage fill the disk.
        $newestUncopied = CountRetentionPolicy::newestUncopied($archives);
        foreach ($archives as $i => $archive) {
            $known[(string) $archive->id] = true;
            if ($i === 0 || (bool) $archive->pinned || ($newestUncopied !== null && $archive->id === $newestUncopied)) {
                $protected[(string) $archive->id] = true;
            }
        }
        $ids = array_values(array_unique(array_filter(
            array_map('strval', $this->policy->select($archives, self::settings())),
            fn (string $id) => isset($known[$id]) && !isset($protected[$id]),
        )));
        foreach ($ids as $id) {
            // Catalogue first: a crash in between leaves an orphan folder, never an "ok" row
            // that points to nothing.
            DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($id) {
                $this->jobs->deleteFor($id);
                $this->archives->delete($id);
            });
            if (!$this->vault->deleteArchive($id)) {
                BackupLog::failure('Archive ' . $id . ' left the catalogue but its folder could not be removed (permissions of the backup folder).');
            }
        }
        $this->forwardToOffsite($ids, $archives);

        return $ids;
    }

    /**
     * Archives removed here whose off-site copy is done go on the off-site retention list
     * (decision BK-6 #4): the next copy job deletes those copies when the credential allows it.
     *
     * @param list<string>  $ids      Removed archive ids.
     * @param list<object>  $archives Catalogue rows read before the removal.
     * @return void
     *
     * @aidlc-unit backup-storage
     * @aidlc-story US-backup-storage-immutable-guide
     */
    private function forwardToOffsite(array $ids, array $archives): void
    {
        if ($ids === []) {
            return;
        }
        try {
            $config = new DestinationConfig;
            if (!$config->configured()) {
                return;
            }
            $removed = array_flip($ids);
            $done = [];
            foreach ($archives as $archive) {
                // WHY not imported archives: one fetched back may be the only clean copy left —
                // whatever a retention policy does here, its off-site copy is never deleted.
                if (isset($removed[(string) $archive->id]) && $archive->offsite === Archive::OFFSITE_DONE && $archive->reason !== Archive::REASON_IMPORT) {
                    $done[] = (string) $archive->id;
                }
            }
            if ($done !== []) {
                $config->addTrash($done);
            }
        } catch (\Throwable $e) {
            // Local retention is done; the off-site copies simply stay (lifecycle rule).
            BackupLog::failure('Off-site retention list not updated: ' . class_basename($e));
        }
    }

    /**
     * @return array{keep: int, keep_pre_change: int}
     */
    public static function settings(): array
    {
        $read = fn (string $key, int $default) => max(1, (int) (AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', $key)->value('value') ?: $default));

        return [
            'keep' => $read(self::CONFIG_KEEP, self::DEFAULT_KEEP),
            'keep_pre_change' => $read(self::CONFIG_KEEP_PRE_CHANGE, self::DEFAULT_KEEP_PRE_CHANGE),
        ];
    }

    /**
     * @return RetentionPolicy
     */
    private static function configuredPolicy(): RetentionPolicy
    {
        $class = config('gp247-config.backup.retention_policy');
        if (is_string($class) && class_exists($class) && is_subclass_of($class, RetentionPolicy::class)) {
            return app($class);
        }

        return new CountRetentionPolicy;
    }
}
