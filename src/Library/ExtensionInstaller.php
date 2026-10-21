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
     * Whether a value may be used as an extension key, i.e. as one folder name
     * under app/GP247/<type>/ (letters, digits, "_" and "-" only).
     *
     * @param string $key Candidate key (from a manifest, a request or the CLI).
     * @return bool
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-001
     * @aidlc-adr plugin-manager_install-input-trust-boundary
     */
    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $key) === 1;
    }

    /**
     * Refusal for a key that is not a plain folder name.
     *
     * @param string $key The rejected key (only echoed back JSON-safe by callers).
     * @return array{error: int, msg: string, detail: string}
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-001
     * @aidlc-adr plugin-manager_install-input-trust-boundary
     */
    protected function invalidKey(string $key): array
    {
        gp247_report(msg: 'Extension install refused: invalid extension key '.json_encode($key), channel: null);

        return ['error' => 1, 'msg' => 'Invalid extension key: only letters, digits, "_" and "-" are allowed', 'detail' => 'invalid_key'];
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
     * Copy an extension's static files (app/GP247/<type>/<Key>/public) to
     * public/GP247/<type>/<Key>, where the browser can reach them.
     *
     * The copy in public/ is derived: it is overwritten, exactly as the 1-click
     * update does. An extension without a public/ folder has nothing to publish.
     *
     * @param string $groupType Plugins|Templates.
     * @param string $key       Extension key.
     * @return array{error: int, msg: string}
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-PLG-local-install-publishes-assets
     * @aidlc-story US-CLI-extension-asset-repair
     * @aidlc-adr system-cli_service-extraction
     */
    public function publishAssets(string $groupType, string $key): array
    {
        $groupType = $this->normalizeType($groupType);
        // WHY: the key becomes a path segment, and gp247:ext-publish takes it from the command line.
        if (!self::isValidKey($key)) {
            return ['error' => 1, 'msg' => 'Invalid extension key: '.$key];
        }

        $relative = 'GP247/'.$groupType.'/'.$key;
        $source = app_path($relative.'/public');
        if (!is_dir($source)) {
            return ['error' => 0, 'msg' => ''];
        }

        // Write-permission preflight (shared-host safety, NFR-AVAIL-cli-shared-host).
        $parent = public_path('GP247/'.$groupType);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        if (!is_dir($parent) || !is_writable($parent)) {
            $msg = 'Publish extension assets error: No write permission '.$parent;
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        try {
            $copied = File::copyDirectory($source, public_path($relative));
        } catch (\Throwable $e) {
            $copied = false;
        }
        if (!$copied) {
            $msg = 'Publish extension assets error: could not copy '.$source.' to '.public_path($relative);
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        return ['error' => 0, 'msg' => ''];
    }

    /**
     * Run the AppConfig install() hook for an extension whose files are already
     * in place (compatibility-checked first), after publishing its static files.
     *
     * WHY publish here and nowhere else: this is the one step every install path
     * shares — the admin "Install" button and gp247:ext-install for a folder that is
     * already on disk (FTP upload, git clone, rsync, Docker image) call it directly,
     * the zip/marketplace path calls it after copying into app/. Publishing only in
     * the zip path left folder installs "successful" with every CSS/JS/image 404.
     * A failed publish returns before install(), so no half-installed extension.
     *
     * @param string $groupType Plugins|Templates.
     * @param string $key       Extension key.
     * @return array{error: int, msg: string}
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-PLG-local-install-publishes-assets
     * @aidlc-adr system-cli_service-extraction
     */
    public function activate(string $groupType, string $key): array
    {
        $groupType = $this->normalizeType($groupType);
        if (!self::isValidKey($key)) {
            return $this->invalidKey($key);
        }

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

        $published = $this->publishAssets($groupType, $key);
        if ($published['error']) {
            return $published;
        }

        $response = (new $class)->install();
        if (is_array($response) && ($response['error'] ?? 1) == 0) {
            // Remember which version was installed, so a later file replacement made
            // outside the marketplace (git pull, composer) can still get its data hook
            // (ExtensionDataUpdater, US-PLG-local-update-data-converge).
            $version = trim((string) ($config['version'] ?? ''));
            if ($version !== '') {
                gp247_extension_set_installed_version($groupType, $key, $version);
            }
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
            gp247_extension_forget_installed_version($groupType, $key);
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
        // WHY: later code in this same request must not see the memoized "present".
        gp247_extension_source_exists($groupType, $key, true);
    }

    /**
     * Install an extension from a .zip file (verify manifest, copy into app +
     * public, run the install hook).
     *
     * @param string $groupType      Expected group type (Plugins|Templates).
     * @param string $zipPath        Absolute path to the .zip on disk.
     * @param bool        $allowOverwriteExisting Reserved; false rejects an existing key.
     * @param string|null $expectedKey  Key the package must declare (library downloads), null = any.
     * @return array{error: int, msg: string, key?: string}
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-001
     * @aidlc-story US-PLG-002
     */
    public function installFromZip(string $groupType, string $zipPath, bool $allowOverwriteExisting = false, ?string $expectedKey = null): array
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
            return $this->installExtractedFolder($groupType, $extractDir, $allowOverwriteExisting, $expectedKey);
        } finally {
            File::deleteDirectory($extractDir);
        }
    }

    /**
     * Install an extension from an already-extracted folder that contains a
     * "<name>/gp247.json" manifest.
     *
     * An extension that is installed in the database but has no source on disk (an
     * "orphan" — e.g. a database cloned from another site) is refused before anything
     * is copied: see installedRefusal().
     *
     * @param string $groupType      Expected group type (Plugins|Templates).
     * @param string $extractDir     Directory holding the extracted extension folder.
     * @param bool        $allowOverwriteExisting Reserved; false rejects an existing key.
     * @param string|null $expectedKey  Key the package must declare (library downloads), null = any.
     * @return array{error: int, msg: string, key?: string, detail?: string}
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-001
     * @aidlc-story US-PLG-002
     * @aidlc-story US-PLG-orphan-source-missing-notice
     * @aidlc-adr plugin-manager_orphan-source-missing-notice
     */
    public function installExtractedFolder(string $groupType, string $extractDir, bool $allowOverwriteExisting = false, ?string $expectedKey = null): array
    {
        $groupType = $this->normalizeType($groupType);

        $checkConfig = glob(rtrim($extractDir, '/').'/*/gp247.json');
        if (!$checkConfig) {
            $msg = 'Import extension error: '.gp247_language_render('admin.extension.error_check_config');
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        $config = json_decode(file_get_contents($checkConfig[0]), true);

        $configGroup = $config['configGroup'] ?? '';
        $configKey = $config['configKey'] ?? '';
        if (!$configGroup || !$configKey) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.error_config_format')];
        }

        // The manifest is untrusted input: its group and key become path segments below.
        if (!is_string($configGroup) || !is_string($configKey)) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.error_config_format'), 'detail' => 'invalid_manifest'];
        }
        if ($configGroup !== $groupType) {
            $msg = 'Package "'.$configKey.'" declares configGroup "'.$configGroup.'", but it is being installed as '.$groupType
                .(in_array($configGroup, ['Plugins', 'Templates'], true) ? ' — install it as '.$configGroup : '');
            gp247_report(msg: 'Extension install refused: '.$msg, channel: null);
            return ['error' => 1, 'msg' => $msg, 'detail' => 'type_mismatch', 'package_type' => in_array($configGroup, ['Plugins', 'Templates'], true) ? $configGroup : ''];
        }
        if (!self::isValidKey($configKey)) {
            return $this->invalidKey($configKey);
        }
        // A package fetched from the library for one key must declare that key: the
        // "installed / orphan" guards ran on the requested key (same rule as updates).
        if ($expectedKey !== null && $configKey !== $expectedKey) {
            gp247_report(msg: 'Extension install refused: package declares "'.$configKey.'" but "'.$expectedKey.'" was requested', channel: null);
            return ['error' => 1, 'msg' => 'Package key mismatch: requested "'.$expectedKey.'", the package declares "'.$configKey.'"', 'detail' => 'key_mismatch'];
        }

        $folderName = basename(dirname($checkConfig[0]));

        $arrLocal = gp247_extension_get_all_local(type: $groupType);
        if (!$allowOverwriteExisting && array_key_exists($configKey, $arrLocal)) {
            $msg = gp247_language_render('admin.extension.error_exist');
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        // Flag row present but no source on disk (checked just above): refuse before
        // copying — install() must not run over existing data.
        $refusal = $this->installedRefusal($groupType, $configKey);
        if ($refusal !== null) {
            return $refusal;
        }

        // WHY after the refusal: an orphan must get the "bring your source" guidance
        // even when this package would not be compatible anyway.
        $requireFaild = gp247_extension_check_compatibility($config ?? []);
        if ($requireFaild) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.not_compatible', ['msg' => json_encode($requireFaild)])];
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
            // public/ is published by activate() from the copy in app/ — one place for every install path.
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
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-002
     * @aidlc-story US-PLG-orphan-source-missing-notice
     * @aidlc-adr plugin-manager_orphan-source-missing-notice
     */
    public function installFromRemote(string $groupType, string $key, array $options = []): array
    {
        $groupType = $this->normalizeType($groupType);

        $isPaid = !empty($options['paid']);
        $license = (string) ($options['license'] ?? '');
        $path = trim((string) ($options['path'] ?? ''));
        if (!self::isValidKey($key)) {
            return $this->invalidKey($key);
        }
        $library = new LibraryClient;

        // Refuse an extension that is already in the database up front — before any
        // download: installed (use gp247:ext-update / ext-uninstall) or orphaned
        // (the operator must bring the source matching the data).
        $refusal = $this->installedRefusal($groupType, $key);
        if ($refusal !== null) {
            return $refusal;
        }

        if (!is_writable(storage_path('tmp'))) {
            $msg = 'No write permission '.storage_path('tmp');
            gp247_report(msg: $msg, channel: null);
            return ['error' => 1, 'msg' => $msg];
        }

        try {
            if ($isPaid) {
                $data = $library->download($groupType, $key, $license);
            } elseif ($path === '') {
                return ['error' => 1, 'msg' => 'Missing download path for free extension'];
            } elseif (!$library->isLibraryUrl($path)) {
                // WHY: never fetch a client-supplied URL off the library host (SSRF / local files).
                gp247_report(msg: 'Extension download refused for "'.$key.'": URL is not on the library host: '.$path, channel: null);
                return ['error' => 1, 'msg' => 'Download refused: the package URL is not on the extension library host', 'detail' => 'download_not_allowed'];
            } else {
                $fetched = $library->fetchPackage($path);
                if (!$fetched['ok']) {
                    return ['error' => 1, 'msg' => 'Download failed: HTTP '.$fetched['status']];
                }
                $data = $fetched['body'];
            }

            // A JSON error body means the server refused (e.g. license problem).
            $jsonData = json_decode((string) $data, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($jsonData['error']) && $jsonData['error'] == 1) {
                return ['error' => 1, 'msg' => $jsonData['msg'] ?? 'Download failed', 'detail' => $jsonData['detail'] ?? '', 'expire' => $jsonData['expire'] ?? null];
            }

            // WHY: uniqid — two installs of the same key in the same second must not share (and delete) one dir.
            $pathTmp = $key.'_'.time().'_'.uniqid();
            $fileTmp = $pathTmp.'.zip';
            Storage::disk('tmp')->put($pathTmp.'/'.$fileTmp, $data);
            $downloadDir = storage_path('tmp/'.$pathTmp);

            // overwrite=false: the up-front guard above already rejected an
            // existing extension, so treat any leftover as a conflict too.
            return $this->installFromZip($groupType, $downloadDir.'/'.$fileTmp, false, $key);
        } catch (\Throwable $e) {
            gp247_report(msg: $e->getMessage(), channel: null);
            return ['error' => 1, 'msg' => $e->getMessage()];
        } finally {
            // WHY: installFromZip() removes only its extract dir; the downloaded zip
            // used to stay in storage/tmp after every library install.
            if (isset($downloadDir)) {
                File::deleteDirectory($downloadDir);
            }
        }
    }

    /**
     * Installed extensions (flag row in the database) whose source is missing on
     * disk, with the version their data is at.
     *
     * Read-only: used by the library screen to show a "source missing" note instead
     * of an install button.
     *
     * @param string $groupType Plugins|Templates.
     * @return array<string, string|null> key => recorded data version (null when unknown).
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-orphan-source-missing-notice
     * @aidlc-adr plugin-manager_orphan-source-missing-notice
     */
    public function orphans(string $groupType): array
    {
        $groupType = $this->normalizeType($groupType);
        $orphans = [];
        foreach (gp247_extension_get_installed(type: $groupType, active: false) as $key => $row) {
            $key = (string) $key;
            // WHY: the installed-version record shares the group but is not an extension.
            if (($row->code ?? '') === 'extension_version' || !self::isValidKey($key)) {
                continue;
            }
            // WHY: admin-only listing — read the disk, not a memo another step of this request may have made stale.
            if (!gp247_extension_source_exists($groupType, $key, true)) {
                $orphans[$key] = gp247_extension_installed_version($groupType, $key);
            }
        }

        return $orphans;
    }

    /**
     * Why an extension may not be installed now, or null when it may.
     *
     * Installed AND source on disk => "already installed" (use update/uninstall).
     * Installed but source missing (an orphan — e.g. a database cloned from another
     * site) => refused with the data version and how to bring the matching source:
     * gp247 never downloads, copies or reinstalls over existing data for it
     * (ADR plugin-manager_orphan-source-missing-notice). Read-only, no network.
     *
     * @param string $groupType Plugins|Templates.
     * @param string $key       Extension key (already validated by the caller).
     * @return array{error: int, msg: string, detail: string, key?: string, version?: ?string}|null
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-orphan-source-missing-notice
     * @aidlc-adr plugin-manager_orphan-source-missing-notice
     */
    public function installedRefusal(string $groupType, string $key): ?array
    {
        $groupType = $this->normalizeType($groupType);
        if (!gp247_extension_check_installed($groupType, $key)) {
            return null;
        }
        if (gp247_extension_source_exists($groupType, $key, true)) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.error_exist'), 'detail' => 'already_installed'];
        }

        $version = gp247_extension_installed_version($groupType, $key);
        $msg = $this->label(
            'orphan_source_missing',
            '":key" is installed in the database (data version :version) but this site has no source for it. GP247 does not install over existing data: '
                .'copy app/GP247/:type/:key and public/GP247/:type/:key from the site this database came from (same version :version). '
                .'If you can only copy app/, run "php artisan gp247:ext-publish --type=:cli_type --key=:key".',
            ['key' => $key, 'version' => $version ?? '?', 'type' => $groupType, 'cli_type' => $groupType === 'Templates' ? 'template' : 'plugin']
        );

        return ['error' => 1, 'msg' => $msg, 'detail' => 'orphan_source_missing', 'key' => $key, 'version' => $version];
    }

    /**
     * Render an admin.extension.* label with an English fallback for sites that
     * have not run the upgrade migration yet, replacing :placeholders.
     *
     * @param string                $code    Code after "admin.extension.".
     * @param string                $default English fallback.
     * @param array<string, string> $params  Placeholder values.
     * @return string
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-orphan-source-missing-notice
     */
    protected function label(string $code, string $default, array $params = []): string
    {
        $text = (string) (gp247_language_quickly('admin.extension.'.$code, $default) ?: $default);
        foreach ($params as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }
}
