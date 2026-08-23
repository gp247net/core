<?php

namespace GP247\Core\Commands;

use GP247\Core\Library\ExtensionInstaller;

/**
 * Uninstall a plugin/template (honoring extension_protected + template guards).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_service-extraction
 */
class ExtUninstall extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-uninstall
        {--type=plugin : plugin|template}
        {--key=* : Extension key(s), repeatable or comma-separated}
        {--only-data : Remove DB config only, keep the source files}';

    /** @var string */
    protected $description = 'Uninstall one or more plugins/templates';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $type = $this->resolveType();
        if ($type === null) {
            return $this->failInvalidType();
        }
        $keys = $this->optionList('key');
        if (!$keys) {
            return $this->respondFailure('missing_key', 'Option --key is required.');
        }

        $onlyData = (bool) $this->option('only-data');
        $installer = (new ExtensionInstaller)->deferCache(count($keys) > 1);
        $res = $this->applyBatch($keys, fn ($k) => $installer->uninstall($type, $k, $onlyData), 'uninstalled');
        if (count($keys) > 1 && $res['succeeded']) {
            gp247_extension_after_update();
        }

        $data = array_merge(['type' => $type], $res);
        if ($res['failed']) {
            return $this->respondFailure('uninstall_failed', count($res['succeeded']).' ok, '.count($res['failed']).' failed', $data);
        }
        return $this->respondSuccess($data);
    }
}
