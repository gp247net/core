<?php

namespace GP247\Core\Library;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Shared engine for the plugin/template ("extension") lifecycle, decoupled from
 * HTTP so both the admin controllers and the CLI (gp247:ext-*) drive the exact
 * same code path (ADR system-cli_service-extraction, NFR-SEC-cli-service-parity).
 *
 * Every method returns a plain array shaped like the AppConfig hooks it wraps:
 *   ['error' => 0|1, 'msg' => string, ...extra].
 * Callers map that to a JSON/redirect response (UI) or a CLI envelope.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_service-extraction
 */
class ExtensionInstaller
{
    /**
     * When true, per-operation route/config cache rebuilds are skipped so a
     * batch caller can rebuild once at the end (avoids N rebuilds for N items
     * on shared hosting).
     *
     * @var bool
     */
    protected bool $deferCache = false;

    /**
     * Toggle deferred cache rebuilding for batch operations.
     *
     * @param bool $defer True to skip per-operation rebuilds.
     * @return self
     */
    public function deferCache(bool $defer = true): self
    {
        $this->deferCache = $defer;
        return $this;
    }

    /**
     * Rebuild route/config caches unless deferred by a batch caller.
     *
     * @return void
     */
    protected function afterUpdate(): void
    {
        if (!$this->deferCache) {
            gp247_extension_after_update();
        }
    }

    /**
     * Normalize a group type to the canonical "Plugins" | "Templates".
     *
     * @param string $groupType Raw group type.
     * @return string Canonical group type.
     */
    protected function normalizeType(string $groupType): string
    {
        return $groupType === 'Templates' ? 'Templates' : 'Plugins';
    }

    /**
     * Resolve the AppConfig class for an extension, or null when absent.
     *
     * @param string $groupType Plugins|Templates.
     * @param string $key       Extension key.
     * @return string|null Fully-qualified AppConfig class, or null.
     */
    protected function appConfigClass(string $groupType, string $key): ?string
    {
        $namespace = gp247_extension_get_namespace(type: $groupType, key: $key);
        $class = $namespace . '\AppConfig';
        return class_exists($class) ? $class : null;
    }

    /**
     * Determine whether an operation on an extension must be blocked, returning
     * a localized reason message, or null when allowed.
     *
     * Enforces extension_protected (uninstall only) plus any runtime guards
     * registered under config('gp247-config.admin.extension.guards') — the hook
     * front uses to protect the in-use / default template without core depending
     * on front (NFR-MAINT-001).
     *
     * @param string $groupType Plugins|Templates.
     * @param string $key       Extension key.
     * @param string $op        Operation: 'uninstall' | 'disable'.
     * @return string|null Block reason, or null when the operation may proceed.
     */
    public function blockReason(string $groupType, string $key, string $op): ?string
    {
        $groupType = $this->normalizeType($groupType);

        if ($op === 'uninstall') {
            $protected = config('gp247-config.admin.extension.extension_protected')[$groupType] ?? [];
            if (in_array($key, array_filter((array) $protected), true)) {
                return gp247_language_render('admin.extension.error_protected', ['key' => $key]);
            }
        }

        foreach ((array) config('gp247-config.admin.extension.guards', []) as $guard) {
            if (!is_callable($guard)) {
                continue;
            }
            $reason = call_user_func($guard, $groupType, $key, $op);
            if (is_string($reason) && $reason !== '') {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Run the AppConfig install() hook for an extension whose files are already
     * in place (compatibility-checked first).
     *
     * @param string $groupType Plugins|Templates.
     * @param string $key       Extension key.
     * @return array{error: int, msg: string}
     */
    public function activate(string $groupType, string $key): array
    {
        $groupType = $this->normalizeType($groupType);

        $manifestPath = app_path('GP247/'.$groupType.'/'.$key.'/gp247.json');
        if (!file_exists($manifestPath)) {
            return ['error' => 1, 'msg' => 'Cannot found file gp247.json'];
        }
        $config = json_decode(file_get_contents($manifestPath), true);
        $requireFaild = gp247_extension_check_compatibility($config ?? []);
        if ($requireFaild) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.not_compatible', ['msg' => json_encode($requireFaild)])];
        }

        $class = $this->appConfigClass($groupType, $key);
        if (!$class) {
            return ['error' => 1, 'msg' => 'Class not found'];
        }
        if (!method_exists($class, 'install')) {
            return ['error' => 1, 'msg' => 'Method install not found'];
        }

        $response = (new $class)->install();
        if (is_array($response) && ($response['error'] ?? 1) == 0) {
            $this->afterUpdate();
        }
        return is_array($response) ? $response : ['error' => 1, 'msg' => 'Unexpected install response'];
    }

    /**
     * Enable an installed extension.
     *
     * @param string $groupType Plugins|Templates.
     * @param string $key       Extension key.
     * @return array{error: int, msg: string}
     */
    public function enable(string $groupType, string $key): array
    {
        $groupType = $this->normalizeType($groupType);
        // Guard: an extension must be installed (has an admin_config row) before it
        // can be enabled. Without this, AppConfig::enable() updates zero rows yet
        // some plugins still report success — a misleading no-op.
        if (!gp247_extension_check_installed($groupType, $key)) {
            return ['error' => 1, 'msg' => 'Extension "'.$key.'" is not installed — run gp247:ext-install first', 'detail' => 'not_installed'];
        }
        $class = $this->appConfigClass($groupType, $key);
        if (!$class || !method_exists($class, 'enable')) {
            return ['error' => 1, 'msg' => 'Method enable not found'];
        }
        $response = (new $class)->enable();
        if (is_array($response) && ($response['error'] ?? 1) == 0) {
            $this->afterUpdate();
        }
        return is_array($response) ? $response : ['error' => 1, 'msg' => 'Unexpected enable response'];
    }

    /**
     * Disable an installed extension (honoring guards).
     *
     * @param string $groupType Plugins|Templates.
     * @param string $key       Extension key.
     * @return array{error: int, msg: string}
     */
    public function disable(string $groupType, string $key): array
    {
        $groupType = $this->normalizeType($groupType);

        if (!gp247_extension_check_installed($groupType, $key)) {
            return ['error' => 1, 'msg' => 'Extension "'.$key.'" is not installed — nothing to disable', 'detail' => 'not_installed'];
        }

        $reason = $this->blockReason($groupType, $key, 'disable');
        if ($reason !== null) {
            gp247_report(msg: $reason, channel: null);
            return ['error' => 1, 'msg' => $reason];
        }

        $class = $this->appConfigClass($groupType, $key);
        if (!$class || !method_exists($class, 'disable')) {
            return ['error' => 1, 'msg' => 'Method disable not found'];
        }
        $response = (new $class)->disable();
        if (is_array($response) && ($response['error'] ?? 1) == 0) {
            $this->afterUpdate();
        }
        return is_array($response) ? $response : ['error' => 1, 'msg' => 'Unexpected disable response'];
    }

    /**
     * Uninstall an extension (honoring guards).
     *
     * When installed: runs the AppConfig uninstall() hook and, unless
     * $onlyRemoveData, deletes the source files after a successful hook.
     *
     * When NOT installed but present on disk (e.g. a bundled plugin): deleting its
     * source is destructive and surprising, so it is refused unless $purge is set
     * (then only the files are removed). When neither installed nor on disk it is a
     * plain "not found".
     *
     * @param string $groupType      Plugins|Templates.
     * @param string $key            Extension key.
     * @param bool   $onlyRemoveData Keep the source directories when true (installed only).
     * @param bool   $purge          Allow deleting the files of a not-installed extension.
     * @return array{error: int, msg: string}
     */
    public function uninstall(string $groupType, string $key, bool $onlyRemoveData = false, bool $purge = false): array
    {
        $groupType = $this->normalizeType($groupType);

        $reason = $this->blockReason($groupType, $key, 'uninstall');
        if ($reason !== null) {
            gp247_report(msg: $reason, channel: null);
            return ['error' => 1, 'msg' => $reason];
        }

        $installed = gp247_extension_check_installed($groupType, $key);
        $onDisk = array_key_exists($key, gp247_extension_get_all_local(type: $groupType));

        if (!$installed) {
            if (!$onDisk) {
                return ['error' => 1, 'msg' => 'Extension "'.$key.'" is not installed', 'detail' => 'not_found'];
            }
            // On disk but never installed — do not silently delete source files.
            if (!$purge) {
                return [
                    'error'  => 1,
                    'msg'    => 'Extension "'.$key.'" is not installed. It exists on disk; pass --purge to delete its files.',
                    'detail' => 'not_installed_use_purge',
                ];
            }
            $this->deleteFiles($groupType, $key);
            return ['error' => 0, 'msg' => 'Removed files for "'.$key.'" (was not installed)'];
        }

        $class = $this->appConfigClass($groupType, $key);
        if (!$class || !method_exists($class, 'uninstall')) {
            return ['error' => 1, 'msg' => 'Method uninstall not found'];
        }

        $response = (new $class)->uninstall();
        $ok = is_array($response) && ($response['error'] ?? 1) == 0;
        if ($ok) {
            $this->afterUpdate();
            // Delete source files only after a successful DB uninstall, unless the
            // caller asked to keep them (onlyRemoveData). A failed hook keeps files
            // so the operator can retry.
            if (!$onlyRemoveData) {
                $this->deleteFiles($groupType, $key);
            }
        }

        return is_array($response) ? $response : ['error' => 1, 'msg' => 'Unexpected uninstall response'];
    }

    /**
     * Delete an extension's source directories from app/ and public/.
     *
     * @param string $groupType Plugins|Templates.
     * @param string $key       Extension key.
     * @return void
     */
    protected function deleteFiles(string $groupType, string $key): void
    {
        $appPath = 'GP247/'.$groupType.'/'.$key;
        File::deleteDirectory(app_path($appPath));
        File::deleteDirectory(public_path($appPath));
    }

    /**
     * Install an extension from a .zip file (verify manifest, copy into app +
     * public, run the install hook).
     *
     * @param string $groupType      Expected group type (Plugins|Templates).
     * @param string $zipPath        Absolute path to the .zip on disk.
     * @param bool   $allowOverwriteExisting Reserved; false rejects an existing key.
     * @return array{error: int, msg: string, key?: string}
     */
    public function installFromZip(string $groupType, string $zipPath, bool $allowOverwriteExisting = false): array
    {
        $groupType = $this->normalizeType($groupType);

        if (!class_exists(\ZipArchive::class)) {
            return ['error' => 1, 'msg' => 'PHP ZipArchive extension is required'];
        }
        if (!file_exists($zipPath)) {
            return ['error' => 1, 'msg' => 'Zip file not found: '.$zipPath];
        }
        if (!is_writable(storage_path('tmp'))) {
            $msg = 'No write permission '.storage_path('tmp');
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        $pathTmp = 'ext_'.md5($zipPath.microtime(true));
        $extractDir = storage_path('tmp/'.$pathTmp);

        if (!gp247_unzip($zipPath, $extractDir)) {
            $msg = 'Import extension error: '.gp247_language_render('admin.extension.error_unzip');
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        try {
            return $this->installExtractedFolder($groupType, $extractDir, $allowOverwriteExisting);
        } finally {
            File::deleteDirectory($extractDir);
        }
    }

    /**
     * Install an extension from an already-extracted folder that contains a
     * "<name>/gp247.json" manifest.
     *
     * @param string $groupType      Expected group type (Plugins|Templates).
     * @param string $extractDir     Directory holding the extracted extension folder.
     * @param bool   $allowOverwriteExisting Reserved; false rejects an existing key.
     * @return array{error: int, msg: string, key?: string}
     */
    public function installExtractedFolder(string $groupType, string $extractDir, bool $allowOverwriteExisting = false): array
    {
        $groupType = $this->normalizeType($groupType);

        $checkConfig = glob(rtrim($extractDir, '/').'/*/gp247.json');
        if (!$checkConfig) {
            $msg = 'Import extension error: '.gp247_language_render('admin.extension.error_check_config');
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        $config = json_decode(file_get_contents($checkConfig[0]), true);
        $requireFaild = gp247_extension_check_compatibility($config ?? []);
        if ($requireFaild) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.not_compatible', ['msg' => json_encode($requireFaild)])];
        }

        $configGroup = $config['configGroup'] ?? '';
        $configKey = $config['configKey'] ?? '';
        if (!$configGroup || !$configKey) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.error_config_format')];
        }

        $folderName = basename(dirname($checkConfig[0]));

        $arrLocal = gp247_extension_get_all_local(type: $groupType);
        if (!$allowOverwriteExisting && array_key_exists($configKey, $arrLocal)) {
            $msg = gp247_language_render('admin.extension.error_exist');
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        // Write-permission preflight (shared-host safety, NFR-AVAIL-cli-shared-host).
        $checkPubPath = public_path('GP247/'.$configGroup);
        if (!is_writable($checkPubPath)) {
            $msg = 'Import extension error: No write permission '.$checkPubPath;
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }
        $checkAppPath = app_path('GP247/'.$configGroup);
        if (!is_writable($checkAppPath)) {
            $msg = 'Import extension error: No write permission '.$checkAppPath;
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        $appPath = 'GP247/'.$configGroup.'/'.$configKey;
        try {
            File::copyDirectory($extractDir.'/'.$folderName.'/public', public_path($appPath));
            File::copyDirectory($extractDir.'/'.$folderName, app_path($appPath));
        } catch (\Throwable $e) {
            $msg = 'Import extension error: '.$e->getMessage();
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        $response = $this->activate($configGroup, $configKey);
        if (is_array($response)) {
            $response['key'] = $configKey;
        }
        return $response;
    }

    /**
     * Install an extension from the marketplace: fetch the zip (free via its
     * public path, paid via the license-gated download endpoint), then install.
     *
     * @param string               $groupType Plugins|Templates.
     * @param string               $key       Extension key.
     * @param array<string, mixed> $options   {path?: string, paid?: bool, license?: string}
     * @return array{error: int, msg: string, key?: string}
     */
    public function installFromRemote(string $groupType, string $key, array $options = []): array
    {
        $groupType = $this->normalizeType($groupType);

        $isPaid = !empty($options['paid']);
        $license = (string) ($options['license'] ?? '');
        $path = (string) ($options['path'] ?? '');

        // Refuse re-installing an already-installed extension up front — before any
        // download. "Installed" means it has an admin_config row (not merely that
        // files exist on disk). Use gp247:ext-update to update, or gp247:ext-uninstall
        // first to reinstall.
        if (gp247_extension_check_installed($groupType, $key)) {
            return [
                'error'  => 1,
                'msg'    => gp247_language_render('admin.extension.error_exist'),
                'detail' => 'already_installed',
            ];
        }

        if (!is_writable(storage_path('tmp'))) {
            $msg = 'No write permission '.storage_path('tmp');
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        try {
            if ($isPaid) {
                $data = (new LibraryClient)->download($groupType, $key, $license);
            } elseif ($path !== '') {
                $data = file_get_contents($path);
            } else {
                return ['error' => 1, 'msg' => 'Missing download path for free extension'];
            }

            // A JSON error body means the server refused (e.g. license problem).
            $jsonData = json_decode((string) $data, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($jsonData['error']) && $jsonData['error'] == 1) {
                return ['error' => 1, 'msg' => $jsonData['msg'] ?? 'Download failed', 'detail' => $jsonData['detail'] ?? '', 'expire' => $jsonData['expire'] ?? null];
            }

            $pathTmp = $key.'_'.time();
            $fileTmp = $pathTmp.'.zip';
            Storage::disk('tmp')->put($pathTmp.'/'.$fileTmp, $data);

            // overwrite=false: the up-front guard above already rejected an
            // existing extension, so treat any leftover as a conflict too.
            return $this->installFromZip($groupType, storage_path('tmp/'.$pathTmp.'/'.$fileTmp), false);
        } catch (\Throwable $e) {
            gp247_report(msg: $e->getMessage(), channel: null);
            return ['error' => 1, 'msg' => $e->getMessage()];
        }
    }
}
