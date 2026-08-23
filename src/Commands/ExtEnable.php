<?php

namespace GP247\Core\Commands;

use GP247\Core\Library\ExtensionInstaller;

/**
 * Enable an installed plugin/template.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_service-extraction
 */
class ExtEnable extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-enable {--type=plugin : plugin|template} {--key=* : Extension key(s), repeatable or comma-separated}';

    /** @var string */
    protected $description = 'Enable one or more installed plugins/templates';

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

        $installer = (new ExtensionInstaller)->deferCache(count($keys) > 1);
        $res = $this->applyBatch($keys, fn ($k) => $installer->enable($type, $k), 'enabled');
        if (count($keys) > 1 && $res['succeeded']) {
            gp247_extension_after_update();
        }

        $data = array_merge(['type' => $type], $res);
        if ($res['failed']) {
            return $this->respondFailure('enable_failed', count($res['succeeded']).' ok, '.count($res['failed']).' failed', $data);
        }
        return $this->respondSuccess($data);
    }
}
