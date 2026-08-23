<?php

namespace GP247\Core\Commands;

use GP247\Core\Library\ExtensionUpdateManager;

/**
 * List locally-installed extensions of a group with their installed/active/
 * version state and whether an update is available (cache-only, no API call).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_output-contract
 */
class ExtList extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-list {--type=plugin : plugin|template}';

    /** @var string */
    protected $description = 'List local plugins/templates with status and available updates';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $type = $this->resolveType();
        if ($type === null) {
            return $this->failInvalidType();
        }

        $updates = (new ExtensionUpdateManager)->getAvailableUpdates();
        $local = gp247_extension_get_all_local(type: $type);

        $items = [];
        foreach ($local as $key => $namespace) {
            $manifest = json_decode(@file_get_contents(app_path('GP247/'.$type.'/'.$key.'/gp247.json')) ?: '[]', true) ?: [];
            $upd = $updates[$type.'|'.$key]['version'] ?? null;
            $items[] = [
                'key'       => $key,
                'version'   => $manifest['version'] ?? '',
                'installed' => gp247_extension_check_installed($type, $key),
                'active'    => gp247_extension_check_active($type, $key),
                'update'    => $upd,
            ];
        }

        if (!$this->isJson()) {
            $rows = array_map(fn ($i) => [
                $i['key'],
                $i['version'],
                $i['installed'] ? 'yes' : 'no',
                $i['active'] ? 'yes' : 'no',
                $i['update'] ?? '-',
            ], $items);
            $this->table(['Key', 'Version', 'Installed', 'Active', 'Update'], $rows);
        }

        return $this->respondSuccess(['type' => $type, 'items' => $items]);
    }
}
