<?php

namespace GP247\Core\Commands;

/**
 * Manage the per-plugin license for a paid extension (stored in admin_config,
 * never in .env). Set with --license, remove with --delete, or show current.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_service-extraction
 */
class ExtLicense extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-license
        {--type=plugin : plugin|template}
        {--key= : Extension key}
        {--license= : License value to store}
        {--delete : Remove the stored license}';

    /** @var string */
    protected $description = 'Set/show/remove the per-plugin license of a paid extension';

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
        if ($key === '') {
            return $this->respondFailure('missing_key', 'Option --key is required.');
        }

        if ($this->option('delete')) {
            gp247_extension_delete_license($type, $key);
            $this->info('License removed for '.$key);
            return $this->respondSuccess(['type' => $type, 'key' => $key, 'action' => 'deleted']);
        }

        $license = $this->option('license');
        if ($license !== null && $license !== '') {
            gp247_extension_save_license($type, $key, (string) $license);
            $this->info('License saved for '.$key);
            return $this->respondSuccess(['type' => $type, 'key' => $key, 'action' => 'saved']);
        }

        // Show current (never echo the full value in JSON — only presence + status).
        $current = gp247_extension_get_license($type, $key);
        $status = gp247_extension_get_license_status($type, $key);
        $this->info($current !== '' ? 'License is set for '.$key : 'No license stored for '.$key);
        return $this->respondSuccess([
            'type'      => $type,
            'key'       => $key,
            'has_license' => $current !== '',
            'status'    => $status,
        ]);
    }
}
