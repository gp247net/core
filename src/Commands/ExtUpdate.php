<?php

namespace GP247\Core\Commands;

use GP247\Core\Library\ExtensionDataUpdater;
use GP247\Core\Library\ExtensionUpdateManager;

/**
 * Apply available marketplace updates for one extension (--key) or all
 * extensions of a group (--all), with backup/rollback handled by the manager.
 *
 * With --local, no download happens: the data hook (AppConfig::update()) is run for
 * extensions whose files were already replaced by other means (git pull, composer,
 * FTP) and whose gp247.json is newer than the version core recorded.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-002
 * @aidlc-story US-PLG-local-update-data-converge
 * @aidlc-adr system-cli_service-extraction
 */
class ExtUpdate extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-update
        {--type=plugin : plugin|template}
        {--key= : Extension key to update}
        {--all : Update every extension of the group that has an available update}
        {--local : Do not download; run the data hook of extensions whose files were updated outside the marketplace (git pull, composer, FTP)}
        {--dry-run : With --local: list what would be applied without running any hook}';

    /** @var string */
    protected $description = 'Update plugin(s)/template(s) from the marketplace (1-click, backup/rollback)';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $type = $this->resolveType();
        if ($type === null) {
            return $this->failInvalidType();
        }

        $key = (string) $this->option('key');
        $all = (bool) $this->option('all');

        if (!$all && $key === '') {
            return $this->respondFailure('missing_key', 'Provide --key=<key> or --all.');
        }

        if ($this->option('local')) {
            return $this->handleLocal($type, $all ? null : $key, (bool) $this->option('dry-run'));
        }

        $manager = new ExtensionUpdateManager;

        $targets = [];
        if ($all) {
            foreach ($manager->checkUpdates(true) as $item) {
                if (($item['type'] ?? '') === $type) {
                    $targets[] = $item['key'];
                }
            }
            if (!$targets) {
                $this->info('No updates available.');
                return $this->respondSuccess(['type' => $type, 'updated' => [], 'failed' => []]);
            }
        } else {
            $targets = [$key];
        }

        $updated = [];
        $failed = [];
        foreach ($targets as $k) {
            $response = $manager->update($type, $k);
            if (($response['error'] ?? 1) == 0) {
                $updated[] = $k;
                $this->info('Updated: '.$k);
            } else {
                $failed[$k] = $response['msg'] ?? 'Update failed';
                $this->addWarning('Failed '.$k.': '.$failed[$k]);
            }
        }

        if ($failed && !$updated) {
            return $this->respondFailure('update_failed', 'All updates failed', ['type' => $type, 'failed' => $failed]);
        }

        return $this->respondSuccess(['type' => $type, 'updated' => $updated, 'failed' => $failed]);
    }

    /**
     * Run (or list, with dry-run) the pending data hooks of locally updated extensions.
     *
     * Exit code is non-zero as soon as ONE hook failed: unlike a marketplace update, a
     * failed hook here means the site is running new files on old data.
     *
     * @param string      $type   Plugins|Templates.
     * @param string|null $key    One key, or null for every extension of the type.
     * @param bool        $dryRun
     * @return int
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-PLG-local-update-data-converge
     */
    protected function handleLocal(string $type, ?string $key, bool $dryRun): int
    {
        $results = (new ExtensionDataUpdater)->applyPending($type, $key, $dryRun);

        $applied = [];
        $pending = [];
        $skipped = [];
        $failed = [];
        foreach ($results as $row) {
            $label = $row['key'].' ('.($row['from'] ?? 'unknown').' -> '.$row['to'].')';
            switch ($row['status']) {
                case 'applied':
                    $applied[] = $row['key'];
                    $this->info('Applied: '.$label);
                    break;
                case 'pending':
                    $pending[] = $row['key'];
                    $this->info('Pending: '.$label);
                    break;
                case 'failed':
                    $failed[$row['key']] = $row['msg'];
                    $this->addWarning('Failed '.$label.': '.$row['msg']);
                    break;
                default:
                    $skipped[] = $row['key'];
                    break;
            }
        }
        if ($key !== null && $results === []) {
            return $this->respondFailure('not_installed', 'Extension "'.$key.'" is not installed or has no source on disk.', ['type' => $type]);
        }
        if (!$applied && !$pending && !$failed) {
            $this->info('Nothing to apply: every installed extension is up to date.');
        }

        $data = ['type' => $type, 'local' => true, 'dry_run' => $dryRun, 'applied' => $applied, 'pending' => $pending, 'skipped' => $skipped, 'failed' => $failed];
        if ($failed) {
            return $this->respondFailure('local_update_failed', count($failed).' data update(s) failed', $data);
        }

        return $this->respondSuccess($data);
    }
}
