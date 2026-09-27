<?php

namespace GP247\Core\Commands;

use GP247\Core\Library\ExtensionInstaller;

/**
 * Copy the static files of installed plugins/templates
 * (app/GP247/<type>/<Key>/public) to public/GP247/<type>/<Key> again.
 *
 * The repair for sites that installed an extension from a folder already on disk
 * before ExtensionInstaller::activate() published static files — their CSS/JS/
 * images answer 404. gp247:doctor (check extension_assets) names them. Uses the
 * same ExtensionInstaller::publishAssets() as the install, so both write the same.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-extension-asset-repair
 * @aidlc-adr system-cli_service-extraction
 */
class ExtPublish extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-publish {--type=plugin : plugin|template} {--key=* : Extension key(s), repeatable or comma-separated} {--all : Every installed extension of the type that ships a public/ folder}';

    /** @var string */
    protected $description = 'Copy the static files (public/) of installed plugins/templates to public/GP247 again';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $type = $this->resolveType();
        if ($type === null) {
            return $this->failInvalidType();
        }

        $keys = $this->option('all') ? $this->installedWithPublic($type) : $this->optionList('key');
        if (!$keys) {
            if ($this->option('all')) {
                return $this->respondSuccess(['type' => $type, 'succeeded' => [], 'failed' => []]);
            }
            return $this->respondFailure('missing_source', 'Provide --key=<Key> or --all.');
        }

        $installer = new ExtensionInstaller;
        $res = $this->applyBatch($keys, function (string $key) use ($installer, $type) {
            if (!gp247_extension_check_installed($type, $key)) {
                return ['error' => 1, 'msg' => $type.'/'.$key.' is not installed'];
            }
            if (!is_dir(app_path('GP247/'.$type.'/'.$key))) {
                return ['error' => 1, 'msg' => 'app/GP247/'.$type.'/'.$key.' is not on disk'];
            }
            return $installer->publishAssets($type, $key);
        }, 'published');

        $data = array_merge(['type' => $type], $res);
        if ($res['failed']) {
            return $this->respondFailure('publish_failed', count($res['succeeded']).' ok, '.count($res['failed']).' failed', $data);
        }
        return $this->respondSuccess($data);
    }

    /**
     * Installed extensions of a type whose folder ships a public/ directory.
     *
     * @param string $type Plugins|Templates.
     * @return array<int, string>
     */
    protected function installedWithPublic(string $type): array
    {
        $keys = [];
        foreach (array_keys(gp247_extension_get_all_local(type: $type)) as $key) {
            if (gp247_extension_check_installed($type, $key) && is_dir(app_path('GP247/'.$type.'/'.$key.'/public'))) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }
}
