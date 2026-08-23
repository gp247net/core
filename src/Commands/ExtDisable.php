<?php

namespace GP247\Core\Commands;

use GP247\Core\Library\ExtensionInstaller;

/**
 * Disable an installed plugin/template (honoring guards).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_service-extraction
 */
class ExtDisable extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-disable {--type=plugin : plugin|template} {--key=* : Extension key(s), repeatable or comma-separated}';

    /** @var string */
    protected $description = 'Disable one or more installed plugins/templates';

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
        $res = $this->applyBatch($keys, fn ($k) => $installer->disable($type, $k), 'disabled');
        if (count($keys) > 1 && $res['succeeded']) {
            gp247_extension_after_update();
        }

        $data = array_merge(['type' => $type], $res);
        if ($res['failed']) {
            return $this->respondFailure('disable_failed', count($res['succeeded']).' ok, '.count($res['failed']).' failed', $data);
        }
        return $this->respondSuccess($data);
    }
}
