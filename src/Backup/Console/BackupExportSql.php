<?php

namespace GP247\Core\Backup\Console;

use GP247\Core\Backup\Application\ArchiveActions;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\SqlExporter;
use GP247\Core\Backup\Console\Concerns\BackupCommandSupport;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Console\GP247Command;

/**
 * Write the data of a backup as a plain `.sql` file (INSERT statements) — command line only, no
 * web route: the file is NOT encrypted and holds personal data. Asks first (or --confirm=<id>),
 * creates the file with mode 0600, in the backup folder unless --output names a path outside
 * the public web root.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-safe-format, US-backup-admin-cli
 * @aidlc-adr backup-engine_archive-format
 */
class BackupExportSql extends GP247Command
{
    use BackupCommandSupport;

    /** @var string */
    protected $signature = 'gp247:backup-export-sql
        {archive : Archive id}
        {--output= : File to write (default: in the backup folder)}
        {--confirm= : The archive id again (required without interaction)}
        {--secret-stdin : Read the backup password (or recovery key) from one line of standard input}
        {--recovery-key : The secret given is the recovery key}
        {--json}';

    /** @var string */
    protected $description = 'Export the data of a backup as a plain (unencrypted) .sql file';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $id = (string) $this->argument('archive');
        $vault = app(LocalVault::class);
        $output = $this->option('output') === null
            ? $vault->root() . DIRECTORY_SEPARATOR . $id . '-' . now()->format('Ymd-His') . '.sql'
            : (string) $this->option('output');
        try {
            LocalVault::assertOutsideWebRoot($output);
        } catch (\Throwable $e) {
            return $this->refusal($e);
        }
        if (!$this->isJson()) {
            $this->warn('The .sql file is NOT encrypted and contains personal data (customers, orders, accounts). Keep it off this server and delete it when done.');
        }
        if (!$this->confirmArchive($id, 'Write the data of this backup unencrypted')) {
            return $this->respondFailure('confirmation_required', 'Not exported: confirm with the archive id (interactively, or --confirm=<archive id>).');
        }
        $secret = $this->readSecret();
        if ($secret === null) {
            return $this->respondFailure('secret_required', 'The backup password or recovery key is required (interactive, or --secret-stdin).');
        }
        try {
            $reader = (new ArchiveActions)->open($id, $secret);
            $result = (new SqlExporter)->export($reader, $output);
        } catch (\Throwable $e) {
            BackupAudit::record('export_sql', BackupAudit::REFUSED, ['archive_id' => $id]);

            return $this->refusal($e);
        }
        BackupAudit::record('export_sql', BackupAudit::OK, ['archive_id' => $id, 'tables' => $result['tables'], 'rows' => $result['rows']]);
        if (!$this->isJson()) {
            $this->info('Written ' . $result['rows'] . ' rows of ' . $result['tables'] . ' tables to ' . $output . '.');
        }

        return $this->respondSuccess($result + ['archive_id' => $id, 'file' => $output]);
    }
}
