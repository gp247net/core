<?php

namespace GP247\Core\Backup\Console;

use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\FileRestoreService;
use GP247\Core\Backup\Application\KeyService;
use GP247\Core\Backup\Application\RestoreService;
use GP247\Core\Backup\Console\Concerns\BackupCommandSupport;
use GP247\Core\Backup\Domain\BackupSecret;
use GP247\Core\Backup\Domain\FileRestorePolicy;
use GP247\Core\Backup\Domain\RestorePlan;
use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Console\GP247Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Restore from the command line — R3, for when the admin cannot be reached
 * (US-backup-restore-cli-restore). Same preview, safety copies, undo and checks as the screen;
 * there is no flag that skips the signature or integrity check.
 *
 *  - data: --level=full | replace (--group / --table) | config | undelete (--table, --key);
 *  - files: --files=uploads,extensions,env (none by default; code and .env only interactively,
 *    never in post-incident mode nor from an archive of unverified origin; composer.lock never);
 *  - --file=<.gp247bak> imports the file first (opened with the password of the site that made it);
 *  - --post-incident (full level): sessions ended, tokens revoked, new admin passwords required;
 *  - --preview shows what would happen and stops; --undo puts back the last replaced tables.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-cli-restore, US-backup-restore-post-incident-mode, US-backup-restore-import-archive
 * @aidlc-adr backup-restore_shadow-table-swap, backup-engine_tamper-evidence
 */
class BackupRestore extends GP247Command
{
    use BackupCommandSupport;

    /** @var string */
    protected $signature = 'gp247:backup-restore
        {archive? : Archive id (or use --file)}
        {--file= : Import this .gp247bak file first and restore from it}
        {--level= : full | replace | config | undelete (leave out to restore files only)}
        {--group=* : Data group (replace)}
        {--table=* : Table (replace: its whole group; undelete: the parent table)}
        {--key=* : Undelete: primary key values to add back (default: every missing row)}
        {--files=* : File groups to write back: uploads, extensions, env}
        {--post-incident : After a security incident: end sessions, revoke tokens, require new passwords}
        {--preview : Show what would be restored and stop}
        {--accept-unverified : Restore an archive whose origin cannot be verified}
        {--accept-column-loss : Restore although columns of the archive no longer exist on the site (their data is lost)}
        {--confirm= : The archive id again (required without interaction)}
        {--undo : Put back the tables the last restore replaced}
        {--secret-stdin : Read the backup password (or recovery key) from one line of standard input}
        {--recovery-key : The secret given is the recovery key}
        {--json}';

    /** @var string */
    protected $description = 'Restore data and / or files from a backup archive (also when the admin is unreachable)';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $this->ensureBackupTables();
        if ((bool) $this->option('undo')) {
            return $this->undo();
        }
        $files = array_values(array_unique(array_filter(array_map('trim', explode(',', implode(',', (array) $this->option('files')))))));
        $level = $this->option('level') === null ? null : (string) $this->option('level');
        if ($level === null && $files === []) {
            return $this->respondFailure('scope_empty', 'Choose what to restore: --level=… and / or --files=….');
        }
        $postIncident = (bool) $this->option('post-incident');
        if ($postIncident && $level !== RestorePlan::FULL) {
            return $this->respondFailure(RestoreRefused::POST_INCIDENT_LEVEL, 'Post-incident mode restores the whole database: use --level=full.');
        }
        $secret = $this->readSecret();
        if ($secret === null) {
            return $this->respondFailure('secret_required', 'The backup password or recovery key is required (interactive, or --secret-stdin).');
        }
        try {
            $archiveId = $this->source($secret);
            if (!is_string($archiveId)) {
                return $archiveId;
            }

            return $this->restore($archiveId, $level, $files, $postIncident, $secret);
        } catch (\Throwable $e) {
            BackupAudit::record('restore', BackupAudit::REFUSED, ['archive_id' => $this->argument('archive'), 'level' => $level]);

            return $this->refusal($e);
        }
    }

    /**
     * @param BackupSecret $secret
     * @return string|int Archive id, or the exit code of a failed import.
     */
    private function source(#[\SensitiveParameter] BackupSecret $secret): string|int
    {
        $file = $this->option('file');
        if ($file === null) {
            $id = (string) $this->argument('archive');

            return $id !== '' ? $id : $this->respondFailure('archive_required', 'Give an archive id or --file=<.gp247bak>.');
        }
        $path = realpath((string) $file);
        if ($path === false || !is_file($path)) {
            return $this->respondFailure('file_not_found', 'The file does not exist.');
        }
        $result = BackupImport::import($path, $secret, fn (int $jobId) => $this->runJob($jobId));
        if ($result instanceof RestoreRefused && $result->reason === RestoreRefused::ALREADY_IN_CATALOGUE && is_string($result->context['archive_id'] ?? null)) {
            // Brought in by an earlier run (a preview, or a run without --confirm): use it.
            return $result->context['archive_id'];
        }
        if ($result instanceof \Throwable) {
            return $this->refusal($result);
        }
        if ($result['status'] !== 'done' || $result['archive_id'] === null) {
            return $this->respondFailure('import_failed', 'The file was not imported: ' . ($result['error'] ?? 'unknown') . '.', $result);
        }
        $this->line('Imported as ' . $result['archive_id'] . ' (origin: ' . $result['origin'] . ').');

        return $result['archive_id'];
    }

    /**
     * @param string       $archiveId
     * @param string|null  $level
     * @param list<string> $files
     * @param bool         $postIncident
     * @param BackupSecret $secret
     * @return int
     * @throws \Throwable
     */
    private function restore(string $archiveId, ?string $level, array $files, bool $postIncident, #[\SensitiveParameter] BackupSecret $secret): int
    {
        $archive = (new ArchiveRepository)->find($archiveId) ?? throw new RestoreRefused(RestoreRefused::NOT_FOUND);
        $service = new RestoreService;
        $dir = app(LocalVault::class)->archiveDir($archiveId);
        $origin = $service->archiveOrigin($archive, $dir);
        $unverified = $origin['origin'] === ArchiveReader::ORIGIN_UNVERIFIED;
        FileRestorePolicy::assertAllowed($files, $this->interactive(), $postIncident, $unverified);
        $options = ['accept_unverified' => (bool) $this->option('accept-unverified'), 'post_incident' => $postIncident, 'created_by' => 'cli',
            'accept_column_loss' => (bool) $this->option('accept-column-loss'), 'via' => 'cli'];
        $plan = $level === null ? null : $this->plan($archiveId, $level);
        $preview = $plan === null ? null : $service->preview($plan, $secret, [], $options);
        $reader = $files === [] ? null : ArchiveReader::open($dir, $secret, new KeyService, $origin['origin']);
        $missing = $reader === null ? [] : array_values(array_diff($files, FileRestoreService::groupsIn($reader)));
        if ($missing !== []) {
            throw new RestoreRefused(RestoreRefused::FILE_GROUP_UNKNOWN, implode(',', $missing));
        }
        $fileService = new FileRestoreService;
        $envDiff = in_array(FileRestorePolicy::ENV, $files, true) ? $fileService->envDiff($reader) : null;
        $summary = ['archive_id' => $archiveId, 'origin' => $origin, 'preview' => $preview, 'files' => $files, 'env_diff' => $envDiff];

        $this->describe($preview, $files, $envDiff, $unverified);
        if ((bool) $this->option('preview')) {
            return $this->respondSuccess($summary);
        }
        if ($unverified && !(bool) $this->option('accept-unverified')) {
            if (!$this->interactive() || !$this->confirm('The origin of this archive cannot be verified (made elsewhere, or the signing key of this site is lost). Restore it anyway?', false)) {
                return $this->respondFailure(RestoreRefused::ORIGIN_UNVERIFIED, 'Not restored: the origin of this archive cannot be verified (confirm with --accept-unverified).', $summary);
            }
            $options['accept_unverified'] = true;
            $preview = $plan === null ? null : $service->preview($plan, $secret, [], $options);
        }
        if ($preview !== null && $preview['refusals'] !== []) {
            return $this->respondFailure((string) $preview['refusals'][0], 'Refused: ' . implode(', ', $preview['refusals']) . '.', $summary);
        }
        if (!$this->confirmSensitive($files) || !$this->confirmArchive($archiveId, 'Restore from this backup')) {
            return $this->respondFailure(RestoreRefused::CONFIRMATION_REQUIRED, 'Not restored: confirm with the archive id (interactively, or --confirm=<archive id>).', $summary);
        }

        $summary['data'] = $plan === null ? null : $this->runData($service, $plan, $secret, $options);
        if ($summary['data'] !== null && $summary['data']['status'] === 'failed') {
            BackupAudit::record('restore', BackupAudit::FAILED, ['archive_id' => $archiveId, 'level' => $level, 'reason' => $summary['data']['reason'] ?? null]);

            return $this->respondFailure('restore_failed', 'The data restore failed: ' . ($summary['data']['reason'] ?? 'unknown') . '. The site data is as before.', $summary);
        }
        $summary['file_results'] = [];
        try {
            foreach ($files as $group) {
                $summary['file_results'][] = $fileService->restore($reader, $group, (int) ($summary['data']['job_id'] ?? now()->format('ymdHis')), $unverified);
            }
        } catch (\Throwable $e) {
            // The data (if any) is restored already and some files may be written: say exactly that.
            BackupAudit::record('restore', BackupAudit::FAILED, ['archive_id' => $archiveId, 'level' => $level, 'reason' => 'files']);
            $this->report($summary);
            $reason = $e instanceof \GP247\Core\Backup\Domain\ArchiveRejected ? 'archive_rejected:' . $e->reason : class_basename($e);

            return $this->respondFailure('files_failed', 'The files of group ' . ($group ?? '') . ' were not all restored (' . $reason . ').'
                . ($summary['data'] !== null ? ' The data restore before it is done.' : '') . ' Overwritten files are kept in the backup folder.', $summary);
        } finally {
            if (in_array(FileRestorePolicy::ENV, $files, true)) {
                Artisan::call('config:clear');
            }
        }
        BackupAudit::record('restore', BackupAudit::OK, ['archive_id' => $archiveId, 'level' => $level, 'files' => implode(',', $files), 'post_incident' => $postIncident]);
        $this->report($summary);

        return $this->respondSuccess($summary);
    }

    /**
     * @param string $archiveId
     * @param string $level
     * @return RestorePlan
     * @throws RestoreRefused
     */
    private function plan(string $archiveId, string $level): RestorePlan
    {
        $tables = (array) $this->option('table');

        return match ($level) {
            RestorePlan::FULL => RestorePlan::full($archiveId),
            RestorePlan::CONFIG => RestorePlan::config($archiveId),
            RestorePlan::REPLACE => RestorePlan::replace($archiveId, (array) $this->option('group'), $tables),
            RestorePlan::UNDELETE => RestorePlan::undelete($archiveId, (string) ($tables[0] ?? ''), $this->option('key') === [] ? null : (array) $this->option('key')),
            default => throw new RestoreRefused(RestoreRefused::LEVEL_INVALID, $level),
        };
    }

    /**
     * @param RestoreService       $service
     * @param RestorePlan          $plan
     * @param BackupSecret         $secret
     * @param array<string, mixed> $options
     * @return array<string, mixed> Result of the restore job (RestoreService::last()).
     */
    private function runData(RestoreService $service, RestorePlan $plan, #[\SensitiveParameter] BackupSecret $secret, array $options): array
    {
        $started = $service->start($plan, $secret, $options);
        $this->runJob($started['job_id']);
        $last = $service->last() ?? [];
        if ((int) ($last['job_id'] ?? 0) !== $started['job_id']) {
            return ['job_id' => $started['job_id'], 'status' => 'failed', 'reason' => 'unknown'];
        }

        return $last;
    }

    /**
     * Code and .env are written only after their own question (interactive runs only — checked
     * by FileRestorePolicy before).
     *
     * @param list<string> $files
     * @return bool
     */
    private function confirmSensitive(array $files): bool
    {
        if (in_array(FileRestorePolicy::EXTENSIONS, $files, true)
            && !$this->confirm('Old code in app/GP247 may contain a backdoor; reinstalling from the source is safer. Write the code of this backup?', false)) {
            return false;
        }
        if (in_array(FileRestorePolicy::ENV, $files, true) && !$this->confirm('Replace .env with the one of this backup (differences above)?', false)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $preview
     * @param list<string>              $files
     * @param array<string, mixed>|null $envDiff
     * @param bool                      $unverified
     * @return void
     */
    private function describe(?array $preview, array $files, ?array $envDiff, bool $unverified): void
    {
        if ($this->isJson()) {
            return;
        }
        if ($unverified) {
            $this->warn('Origin not verified: this archive was not signed by this site (or the signing key is lost). Code and .env cannot be restored from it.');
        }
        if ($preview !== null) {
            $this->line('Archive ' . $preview['archive']['id'] . ' of ' . $preview['archive']['created_at'] . ' — level ' . $preview['level']);
            $this->table(['Table', 'Rows on the site', 'Rows in the backup', 'Changed since'], array_map(fn ($t) => [
                $t['name'] ?? '', $t['rows_site'] ?? '', $t['rows_archive'] ?? '', $t['rows_new_since_backup'] ?? '',
            ], (array) $preview['tables']));
            if (!empty($preview['post_incident'])) {
                $this->warn('Post-incident mode: every session ends (yours too), every API / MCP token is revoked (integrations need new tokens), every admin must choose a new password.');
            }
            // Mod 20261110T164834: what does not come back, said before the restore runs.
            foreach ((array) ($preview['lost_columns'] ?? []) as $table => $columns) {
                $this->warn('Data lost — column(s) no longer on the site: ' . $table . '.' . implode(', ' . $table . '.', (array) $columns) . ' (confirm with --accept-column-loss).');
            }
            foreach (['absent' => 'not on this site', 'excluded' => 'never replaced', 'not_in_archive' => 'not in the backup'] as $key => $why) {
                if (!empty($preview[$key])) {
                    $this->line('Skipped (' . $why . '): ' . implode(', ', (array) $preview[$key]));
                }
            }
            foreach ((array) ($preview['compatibility']['missing_by_source'] ?? []) as $source => $migrations) {
                $this->warn('Missing on this site (' . $source . '): ' . implode(', ', array_slice((array) $migrations, 0, 10)) . (count((array) $migrations) > 10 ? ' …' : ''));
            }
        }
        foreach ($files as $group) {
            $this->line('Files: ' . $group . ($group === FileRestorePolicy::UPLOADS ? ' (executable / server set-up files are skipped)' : ''));
        }
        foreach ((array) ($envDiff['keys'] ?? []) as $entry) {
            $this->line('  .env ' . $entry['status'] . ': ' . $entry['key'] . ' = ••••');
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @return void
     */
    private function report(array $summary): void
    {
        if ($this->isJson()) {
            return;
        }
        if (is_array($summary['data'] ?? null)) {
            $this->info('Data restored: ' . ($summary['data']['status'] ?? '') . '.');
            foreach ((array) ($summary['data']['post_incident'] ?? []) as $step) {
                $this->line('  ' . $step['step'] . ': ' . $step['status'] . ($step['count'] ? ' (' . $step['count'] . ')' : ''));
            }
        }
        foreach ((array) ($summary['file_results'] ?? []) as $result) {
            $this->info('Files ' . $result['group'] . ': ' . $result['written'] . ' written, ' . count($result['skipped']) . ' skipped' . ($result['kept'] ? ', previous versions kept in ' . $result['copies'] : '') . '.');
        }
        if (!empty($summary['data']['post_incident'])) {
            $this->warn('Still to do by hand: put a new GP247_ENCRYPTION_KEY in .env, move the old one to GP247_ENCRYPTION_PREVIOUS_KEYS, run "php artisan gp247:encryption-key-rotate", remove the old key only after it finished; reinstall the code from its source; change the database and hosting passwords; run "php artisan gp247:doctor".');
        }
    }

    /**
     * @return int
     */
    private function undo(): int
    {
        if (!$this->interactive() && $this->option('confirm') !== 'undo') {
            return $this->respondFailure(RestoreRefused::CONFIRMATION_REQUIRED, 'Not undone: confirm with --confirm=undo.');
        }
        if ($this->interactive() && !$this->confirm('Put back the tables the last restore replaced?', false)) {
            return $this->respondFailure(RestoreRefused::CONFIRMATION_REQUIRED, 'Not undone.');
        }
        try {
            $result = (new RestoreService)->undo();
        } catch (\Throwable $e) {
            return $this->refusal($e);
        }
        BackupAudit::record('restore_undo', BackupAudit::OK, ['tables' => count($result['tables'])]);
        if (!$this->isJson()) {
            $this->info('Put back ' . count($result['tables']) . ' table(s).');
        }

        return $this->respondSuccess($result);
    }
}
