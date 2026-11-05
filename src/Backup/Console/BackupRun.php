<?php

namespace GP247\Core\Backup\Console;

use GP247\Core\Backup\Application\BackupService;
use GP247\Core\Backup\Application\JobRunner;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\BackupNotSetUp;
use GP247\Core\Backup\Domain\BackupRefused;
use GP247\Core\Backup\Domain\BackupScope;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Console\GP247Command;

/**
 * Make a backup from the command line in one go — the fast path: every table is read inside
 * one database snapshot (archive marked "consistent" on MySQL / MariaDB), no time budget,
 * verification included. `--job=<id>` finishes a job started elsewhere (e.g. from the admin).
 *
 * Other backup commands (list, verify, pin, delete, restore, key) come with BK-5.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run, US-backup-engine-full-backup, US-backup-engine-selective-backup, US-backup-engine-quick-snapshot
 * @aidlc-adr backup-engine_sliced-job-runner
 */
class BackupRun extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247:backup-run
        {--kind=full : snapshot | selective | full}
        {--group=* : Data group key (snapshot: one, selective: one or more)}
        {--files=* : File group key for a selective backup (uploads, extensions, env, lock)}
        {--exclude-logs : Leave the log tables out of a full backup}
        {--reason=manual : manual | scheduled | pre_change:<operation>}
        {--job= : Finish an existing backup job instead of starting one}
        {--json}';

    /** @var string */
    protected $description = 'Create an encrypted backup archive (one consistent run) and verify it';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        try {
            if ($this->option('job') !== null) {
                $result = (new JobRunner)->runToEnd((int) $this->option('job'));
            } else {
                $created = (new BackupService)->create($this->scope(), (string) $this->option('reason'), [
                    'via' => BackupService::VIA_CLI,
                    'created_by' => 'cli',
                ]);
                $result = (new JobRunner)->runToEnd($created['job_id']);
            }
        } catch (BackupNotSetUp $e) {
            return $this->respondFailure('not_set_up', 'No backup password is set up yet: set it up in Admin > Backup first.');
        } catch (BackupRefused $e) {
            return $this->respondFailure($e->reason, $e->getMessage(), self::safeContext($e->context));
        }

        $archive = $result['archive_id'] === null ? null : (new ArchiveRepository)->find($result['archive_id']);
        $data = [
            'archive_id' => $result['archive_id'],
            'status' => $archive->status ?? null,
            'kind' => $archive->kind ?? null,
            'consistency' => $archive->consistency ?? null,
            'size' => isset($archive->size) ? (int) $archive->size : null,
            'parts' => isset($archive->parts) ? (int) $archive->parts : null,
        ];
        if (($archive->status ?? null) !== Archive::STATUS_OK) {
            return $this->respondFailure('backup_failed', 'The backup did not finish: ' . ($archive->error ?? $result['status']), $data);
        }
        if (!$this->isJson()) {
            $this->info('Backup ' . $data['archive_id'] . ' — ' . $data['kind'] . ', ' . $data['consistency'] . ', ' . $data['size'] . ' bytes, verified.');
        }

        return $this->respondSuccess($data);
    }

    /**
     * @return BackupScope
     * @throws BackupRefused
     */
    private function scope(): BackupScope
    {
        $groups = (array) $this->option('group');

        return match ((string) $this->option('kind')) {
            Archive::KIND_SNAPSHOT => BackupScope::snapshot((string) ($groups[0] ?? BackupScope::DEFAULT_SNAPSHOT_GROUP)),
            Archive::KIND_SELECTIVE => BackupScope::selective($groups, (array) $this->option('files')),
            Archive::KIND_FULL => BackupScope::full((bool) $this->option('exclude-logs')),
            default => throw new BackupRefused(BackupRefused::SCOPE_EMPTY, 'unknown kind'),
        };
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed> Numbers only (no paths).
     */
    private static function safeContext(array $context): array
    {
        $out = [];
        foreach (['free', 'needed', 'limit'] as $key) {
            if (isset($context[$key])) {
                $out[$key] = (int) $context[$key];
            }
        }
        if (isset($context['estimate']['total_bytes'])) {
            $out['estimate_bytes'] = (int) $context['estimate']['total_bytes'];
        }

        return $out;
    }
}
