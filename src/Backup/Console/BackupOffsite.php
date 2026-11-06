<?php

namespace GP247\Core\Backup\Console;

use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\ImportService;
use GP247\Core\Backup\Application\OffsiteService;
use GP247\Core\Backup\Console\Concerns\BackupCommandSupport;
use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Domain\OffsiteRefused;
use GP247\Core\Backup\Domain\RemoteLayout;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Console\GP247Command;

/**
 * The external destination from the command line — the way back when the admin screen is out of
 * reach (moving to a new server, a broken site): check the connection, list the archives kept
 * there, copy one archive out again, or bring one back into the catalogue. The destination itself
 * is configured on the backup settings screen (Administrator + re-confirmation); this command
 * never changes it and never accepts a new SFTP host key.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-fetch-remote, US-backup-storage-chunked-upload, US-backup-storage-s3-compatible
 * @aidlc-adr backup-storage_optional-adapters
 */
class BackupOffsite extends GP247Command
{
    use BackupCommandSupport;

    /** @var string */
    protected $signature = 'gp247:backup-offsite
        {action : test | list | push | fetch}
        {archive? : Archive id (push, fetch)}
        {--secret-stdin : fetch: read the backup password (or recovery key) from one line of standard input}
        {--recovery-key : fetch: the secret given is the recovery key}
        {--json}';

    /** @var string */
    protected $description = 'External backup destination: test the connection, list, copy out or fetch back an archive';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $this->ensureBackupTables();
        $offsite = new OffsiteService;
        if (!$offsite->configured()) {
            return $this->respondFailure('not_configured', 'No external destination is set (Backup → Settings).');
        }
        $action = (string) $this->argument('action');
        $archive = $this->argument('archive');
        if (in_array($action, ['push', 'fetch'], true) && (!is_string($archive) || !RemoteLayout::isArchiveId($archive))) {
            return $this->respondFailure('archive_required', 'Give the archive id (26 characters) to ' . $action . '.');
        }

        try {
            return match ($action) {
                'test' => $this->test($offsite),
                'list' => $this->listRemote($offsite),
                'push' => $this->push($offsite, (string) $archive),
                'fetch' => $this->fetch((string) $archive),
                default => $this->respondFailure('invalid_action', 'Action must be test, list, push or fetch.'),
            };
        } catch (DestinationFailure $e) {
            BackupAudit::record('cli_offsite_' . $action, BackupAudit::FAILED, ['reason' => $e->reason]);

            return $this->respondFailure('destination_' . $e->reason, 'The external destination failed: ' . $e->reason . '.');
        } catch (OffsiteRefused $e) {
            return $this->respondFailure($e->reason, 'Refused: ' . $e->reason . '.');
        }
    }

    /**
     * Test the stored destination and keep the rights found (no host key is pinned here).
     *
     * @param OffsiteService $offsite
     * @return int
     */
    private function test(OffsiteService $offsite): int
    {
        $current = (array) $offsite->config()->current();
        $report = $offsite->test((string) $current['type'], (array) $current['fields'], []);
        $offsite->config()->saveReport($report);
        BackupAudit::record('cli_offsite_test', $report->ok() ? BackupAudit::OK : BackupAudit::FAILED, ['kind' => (string) $current['type'], 'reason' => (string) $report->error]);
        $data = ['type' => (string) $current['type']] + $report->toArray();
        if (!$this->isJson()) {
            $rights = $report->capabilities->toArray();
            $this->table(['Write', 'Read', 'List', 'Delete'], [array_map(fn ($v) => $v ? 'yes' : 'no', array_values($rights))]);
            if ($report->fingerprint !== null) {
                $this->line('Host key: ' . $report->fingerprint . ($current['fingerprint'] === $report->fingerprint ? ' (pinned)' : ''));
            }
            if ($report->capabilities->usable() && $report->capabilities->delete) {
                $this->warn('This credential may delete: a write-only credential with Object Lock keeps copies safe from an attacker on the site.');
            }
        }
        if (!$report->ok()) {
            return $this->respondFailure('destination_' . ($report->error ?? 'not_usable'), 'The destination cannot be used: ' . ($report->error ?? 'missing ' . implode(', ', $report->capabilities->missing())) . '.', $data);
        }

        return $this->respondSuccess($data);
    }

    /**
     * @param OffsiteService $offsite
     * @return int
     */
    private function listRemote(OffsiteService $offsite): int
    {
        $rows = array_map(fn ($a) => $a->toArray(), $offsite->remoteArchives());
        BackupAudit::record('cli_offsite_list', BackupAudit::OK, ['status' => (string) count($rows)]);
        if (!$this->isJson()) {
            $this->table(['Id', 'Size', 'Files', 'Complete', 'In catalogue'], array_map(fn ($r) => [
                $r['id'], $r['bytes'], $r['files'], $r['complete'] ? 'yes' : 'no', $r['in_catalogue'] ? 'yes' : '',
            ], $rows));
        }

        return $this->respondSuccess(['archives' => $rows]);
    }

    /**
     * Copy a verified archive out again (after a failed copy, or one made before the destination was set).
     *
     * @param OffsiteService $offsite
     * @param string         $archiveId
     * @return int
     */
    private function push(OffsiteService $offsite, string $archiveId): int
    {
        $jobId = $offsite->retry($archiveId);
        BackupAudit::record('cli_offsite_push', BackupAudit::OK, ['archive_id' => $archiveId, 'job_id' => $jobId]);
        $run = $this->runJob($jobId);
        $archive = (new ArchiveRepository)->find($archiveId);
        $data = ['archive_id' => $archiveId, 'job_id' => $jobId, 'offsite' => $archive === null ? null : (string) $archive->offsite];
        if ($data['offsite'] !== 'done') {
            return $this->respondFailure('push_failed', 'The archive was not copied out (' . ($run['status'] ?? 'unknown') . ').', $data);
        }
        if (!$this->isJson()) {
            $this->info('Archive ' . $archiveId . ' is copied to the external destination.');
        }

        return $this->respondSuccess($data);
    }

    /**
     * Bring an archive back from the destination into the catalogue (checked in full first).
     *
     * @param string $archiveId
     * @return int
     */
    private function fetch(string $archiveId): int
    {
        $secret = $this->readSecret('Password or recovery key of the site that made this backup');
        if ($secret === null) {
            return $this->respondFailure('secret_required', 'The backup password or recovery key is required (interactive, or --secret-stdin).');
        }
        try {
            $service = new ImportService;
            $started = $service->startFromRemote($archiveId, $secret, ['created_by' => 'cli']);
            BackupAudit::record('cli_offsite_fetch', BackupAudit::OK, ['archive_id' => $archiveId, 'job_id' => $started['job_id']]);
            $this->runJob($started['job_id']);
            $result = $service->result($started['job_id']);
        } catch (DestinationFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->refusal($e);
        }
        if ($result['status'] !== 'done') {
            return $this->respondFailure('fetch_failed', 'The archive was not brought back: ' . ($result['error'] ?? 'unknown') . '.', $result);
        }
        if (!$this->isJson()) {
            $this->info('Brought back as ' . $result['archive_id'] . ' (origin: ' . $result['origin'] . ').');
        }

        return $this->respondSuccess($result);
    }
}
