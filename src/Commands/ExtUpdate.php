<?php

namespace GP247\Core\Commands;

use GP247\Core\Library\ExtensionUpdateManager;

/**
 * Apply available marketplace updates for one extension (--key) or all
 * extensions of a group (--all), with backup/rollback handled by the manager.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_service-extraction
 */
class ExtUpdate extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-update
        {--type=plugin : plugin|template}
        {--key= : Extension key to update}
        {--all : Update every extension of the group that has an available update}';

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

        $manager = new ExtensionUpdateManager;
        $key = (string) $this->option('key');
        $all = (bool) $this->option('all');

        if (!$all && $key === '') {
            return $this->respondFailure('missing_key', 'Provide --key=<key> or --all.');
        }

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
}
