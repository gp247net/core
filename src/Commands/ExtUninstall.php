<?php

namespace GP247\Core\Commands;

use GP247\Core\Library\ExtensionInstaller;

/**
 * Uninstall a plugin/template (honoring extension_protected + template guards).
 *
 * --forget removes only the registration of an orphan (installed, source missing), the
 * same service as the Forget button of the Plugins/Templates screens.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-002
 * @aidlc-story US-PLG-orphan-forget-registration
 * @aidlc-adr system-cli_service-extraction
 * @aidlc-adr plugin-manager_orphan-forget-registration
 */
class ExtUninstall extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-uninstall
        {--type=plugin : plugin|template}
        {--key=* : Extension key(s), repeatable or comma-separated}
        {--only-data : Remove DB config only, KEEP source files (installed only)}
        {--purge : Delete source files of a NOT-installed (on-disk) extension too}
        {--forget : Forget an installed extension whose source is missing (keeps its own data)}';

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

        // The two flags sit on opposite ends of the "files" axis: --only-data keeps
        // files, --purge deletes files. Together they contradict each other.
        if ($this->option('only-data') && $this->option('purge')) {
            return $this->respondFailure('conflicting_options', '--only-data (keep files) and --purge (delete files) cannot be combined.');
        }

        // --forget acts on the registration only; mixing it with a files flag has no meaning.
        if ($this->option('forget') && ($this->option('only-data') || $this->option('purge'))) {
            return $this->respondFailure('conflicting_options', '--forget cannot be combined with --only-data or --purge.');
        }

        $onlyData = (bool) $this->option('only-data');
        $purge = (bool) $this->option('purge');
        $installer = (new ExtensionInstaller)->deferCache(count($keys) > 1);
        $res = $this->option('forget')
            ? $this->applyBatch($keys, fn ($k) => $installer->forgetOrphan($type, $k), 'forgotten')
            : $this->applyBatch($keys, fn ($k) => $installer->uninstall($type, $k, $onlyData, $purge), 'uninstalled');
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
