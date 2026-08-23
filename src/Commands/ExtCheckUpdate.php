<?php

namespace GP247\Core\Commands;

use GP247\Core\Library\ExtensionUpdateManager;

/**
 * Batch-check the marketplace for available updates (pipeline-friendly), for one
 * group. Uses the cached result unless --force is given.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_output-contract
 */
class ExtCheckUpdate extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-check-update {--type=plugin : plugin|template} {--force : Bypass cache and call the API now}';

    /** @var string */
    protected $description = 'Check the marketplace for available plugin/template updates';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $type = $this->resolveType();
        if ($type === null) {
            return $this->failInvalidType();
        }

        $updates = (new ExtensionUpdateManager)->checkUpdates((bool) $this->option('force'));

        $items = [];
        foreach ($updates as $item) {
            if (($item['type'] ?? '') === $type) {
                $items[] = [
                    'key'           => $item['key'] ?? '',
                    'version_local' => $item['version_local'] ?? '',
                    'version'       => $item['version'] ?? '',
                    'require_license' => (bool) ($item['require_license'] ?? false),
                ];
            }
        }

        if (!$this->isJson()) {
            if ($items) {
                $this->table(
                    ['Key', 'Local', 'Available', 'Needs license'],
                    array_map(fn ($i) => [$i['key'], $i['version_local'], $i['version'], $i['require_license'] ? 'yes' : 'no'], $items)
                );
            } else {
                $this->info('No updates available.');
            }
        }

        return $this->respondSuccess(['type' => $type, 'count' => count($items), 'items' => $items]);
    }
}
