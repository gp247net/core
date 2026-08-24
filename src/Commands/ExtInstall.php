<?php

namespace GP247\Core\Commands;

use GP247\Core\Library\ExtensionInstaller;
use GP247\Core\Library\LibraryClient;

/**
 * Install a plugin/template from an offline .zip, an extracted directory, or the
 * remote marketplace (free or license-gated paid), via the shared installer.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_service-extraction
 */
class ExtInstall extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-install
        {--type=plugin : plugin|template}
        {--file=* : Path(s) to local .zip archive(s)}
        {--dir=* : Path(s) to extracted directory(ies) containing <name>/gp247.json}
        {--key=* : Marketplace extension key(s) (remote install)}
        {--license= : Per-plugin license for a paid remote extension}
        {--paid : Treat the remote extension(s) as paid (license-gated download)}';

    /** @var string */
    protected $description = 'Install one or more plugins/templates from .zip(s), directory(ies), or the marketplace';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $type = $this->resolveType();
        if ($type === null) {
            return $this->failInvalidType();
        }

        $files = $this->optionList('file');
        $dirs = $this->optionList('dir');
        $keys = $this->optionList('key');
        $total = count($files) + count($dirs) + count($keys);
        if ($total === 0) {
            return $this->respondFailure('missing_source', 'Provide at least one of --file, --dir or --key.');
        }

        // A single --license cannot map to multiple paid extensions, and it would
        // be recorded against the wrong plugin. Refuse the ambiguous combination
        // early instead of silently applying one license to every key.
        if ($this->option('paid') && count($keys) > 1) {
            return $this->respondFailure(
                'paid_multi_not_allowed',
                'Paid extensions must be installed one at a time: --paid cannot be combined with multiple --key '
                    .'(a single --license would be applied to the wrong plugins). Run one --key per command.'
            );
        }

        // One installer instance; defer per-item cache rebuilds for a batch and
        // rebuild once at the end (shared-host friendly).
        $installer = (new ExtensionInstaller)->deferCache($total > 1);

        $succeeded = [];
        $failed = [];

        foreach ($files as $file) {
            $this->record($installer->installFromZip($type, $file), $file, $succeeded, $failed);
        }
        foreach ($dirs as $dir) {
            $this->record($installer->installExtractedFolder($type, $dir), $dir, $succeeded, $failed);
        }
        foreach ($keys as $key) {
            $this->record($this->installRemote($installer, $type, $key), $key, $succeeded, $failed);
        }

        if ($total > 1 && $succeeded) {
            gp247_extension_after_update();
        }

        $data = ['type' => $type, 'succeeded' => $succeeded, 'failed' => $failed];
        if ($failed) {
            return $this->respondFailure('install_failed', count($succeeded).' ok, '.count($failed).' failed', $data);
        }
        return $this->respondSuccess($data);
    }

    /**
     * Record one install result into the succeeded/failed accumulators.
     *
     * @param array|mixed          $response  Installer result.
     * @param string               $source    The source identifier (file/dir/key).
     * @param array<int, string>   $succeeded By-ref list of installed keys.
     * @param array<string, string> $failed   By-ref map source => error message.
     * @return void
     */
    protected function record($response, string $source, array &$succeeded, array &$failed): void
    {
        if (is_array($response) && ($response['error'] ?? 1) == 0) {
            $key = $response['key'] ?? $source;
            $succeeded[] = $key;
            $this->info('Installed: '.$key);
        } else {
            $failed[$source] = is_array($response) ? ($response['msg'] ?? 'Install failed') : 'Install failed';
            $this->addWarning('Failed '.$source.': '.$failed[$source]);
        }
    }

    /**
     * Resolve and install a marketplace extension by key.
     *
     * Paid items use the license-gated download; free items are resolved to their
     * public download path by querying the marketplace listing.
     *
     * @param ExtensionInstaller $installer Shared installer.
     * @param string             $type      Plugins|Templates.
     * @param string             $key       Marketplace key.
     * @return array{error: int, msg: string, key?: string, detail?: string}
     */
    protected function installRemote(ExtensionInstaller $installer, string $type, string $key): array
    {
        // 1) Already installed (has an admin_config row) → refuse. Use ext-update
        //    to update or ext-uninstall to reinstall.
        if (gp247_extension_check_installed($type, $key)) {
            return [
                'error'  => 1,
                'msg'    => gp247_language_render('admin.extension.error_exist'),
                'detail' => 'already_installed',
            ];
        }

        // 2) Files already on disk but NOT installed (e.g. a bundled plugin like
        //    News) → run the local install/activate, exactly like the admin
        //    "Install" button; no marketplace call.
        if (array_key_exists($key, gp247_extension_get_all_local(type: $type))) {
            return $installer->activate($type, $key);
        }

        // 3) Not on disk → fetch from the marketplace.
        if ($this->option('paid')) {
            return $installer->installFromRemote($type, $key, [
                'paid'    => true,
                'license' => (string) $this->option('license'),
            ]);
        }

        // Free install: look up the item's public download URL from the listing.
        $listing = (new LibraryClient)->list($type, ['keyword' => $key, 'page[size]' => 50]);

        // WHY: distinguish a real API failure (403 domain_not_authorized, network,
        // TLS, misconfigured endpoint) from a genuinely empty result. Without this
        // the swallowed error surfaced as the misleading "not found in the
        // marketplace" (RISK-TECH-cli-marketplace-error-swallow).
        if (!empty($listing['error'])) {
            return ['error' => 1, 'msg' => 'Marketplace error: '.$listing['error'].$this->licenseHint($listing)];
        }

        $downloadUrl = '';
        $isFree = true;
        $found = false;
        foreach (($listing['data'] ?? []) as $item) {
            if (($item['key'] ?? '') === $key) {
                $found = true;
                // WHY: on the browse/search `list` endpoint the free public download
                // URL lives in 'file' (the filev3 link), NOT 'path' — 'path' is
                // always empty here; it only carries a URL on the separate
                // /check-update endpoint used by ext-update. The admin "Install"
                // button sends exactly this 'file' value (extension_online.blade.php:
                // installOnline(key, extension.file)). Reading 'path' misreported an
                // available free plugin as "not found in the marketplace"
                // (RISK-TECH-cli-freeinstall-field-mismatch).
                $downloadUrl = ($item['file'] ?? '') ?: ($item['path'] ?? '');
                $isFree = (bool) ($item['is_free'] ?? 0);
                break;
            }
        }

        if ($downloadUrl === '') {
            if ($found && !$isFree) {
                return ['error' => 1, 'msg' => 'Extension "'.$key.'" is paid — pass --paid --license=...'];
            }
            if ($found) {
                return ['error' => 1, 'msg' => 'Extension "'.$key.'" has no public download URL in the marketplace listing'];
            }
            return ['error' => 1, 'msg' => 'Extension "'.$key.'" not found in the marketplace'];
        }

        return $installer->installFromRemote($type, $key, ['path' => $downloadUrl]);
    }

    /**
     * Build a hint pointing the operator at gp247:ext-register-license when a
     * marketplace failure is an unregistered-domain / missing-license problem.
     *
     * The same unregistered domain that trips RISK-TECH-cli-marketplace-error-swallow
     * is fixed by registering this domain's API-connection license, so we suggest
     * the fix right where the error surfaces (mod 20260824T115233). Only a hint —
     * we never auto-run it.
     *
     * @param array $listing The LibraryClient::list() error payload (may carry a
     *   marketplace 'code' and normalized 'error' string).
     * @return string A suffix to append to the error message, or '' when the
     *   failure is unrelated to licensing/domain authorization.
     */
    protected function licenseHint(array $listing): string
    {
        $code = strtolower((string) ($listing['code'] ?? ''));
        $error = strtolower((string) ($listing['error'] ?? ''));

        $isLicenseIssue = in_array($code, ['api_license_required', 'domain_not_authorized'], true)
            || str_contains($error, 'license')
            || str_contains($error, 'domain not authorized');

        if (!$isLicenseIssue) {
            return '';
        }

        return ' → Run \'php artisan gp247:ext-register-license\' to register this domain\'s API license.';
    }
}
