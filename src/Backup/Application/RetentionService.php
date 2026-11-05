<?php

namespace GP247\Core\Backup\Application;

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
        // last good archive, a pinned one, or one whose off-site copy is not done.
        $protected = [];
        $known = [];
        foreach ($archives as $i => $archive) {
            $known[(string) $archive->id] = true;
            if ($i === 0 || (bool) $archive->pinned || in_array($archive->offsite, ['pending', 'failed'], true)) {
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
            $this->vault->deleteArchive($id);
        }

        return $ids;
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
