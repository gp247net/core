<?php
namespace GP247\Core\Library;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Detect and apply updates for local extensions (Plugins/Templates) from the
 * GP247 marketplace API.
 *
 * Detection is synchronous with a cache so it works on shared hosts without
 * cron/queue (NFR-AVAIL-001). Applying an update follows
 * backup -> download -> validate -> replace files -> AppConfig::update() hook,
 * and restores from backup when anything fails after live files were touched.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-005
 * @aidlc-adr plugin-manager_extension-update-flow
 */
class ExtensionUpdateManager
{
    const CACHE_KEY = 'gp247_extension_updates';
    const BACKUP_KEEP = 3;
    const CHECK_TIMEOUT = 20; //seconds
    const DOWNLOAD_TIMEOUT = 120; //seconds

    /**
     * Return updates already discovered, from cache only (no API call).
     * Safe to call on every admin screen render.
     *
     * @return array Map "<Type>|<key>" => update item.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    public function getAvailableUpdates(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        return is_array($cached) ? $cached : [];
    }

    /**
     * Compare local extension versions with the marketplace via the batch
     * check-update endpoint. Result is cached; pass $force to bypass cache.
     *
     * Degrades softly: any API failure is logged and treated as "no updates"
     * so admin screens keep working when the endpoint is unreachable.
     *
     * @param bool $force Bypass the cache and call the API now.
     * @return array Map "<Type>|<key>" => update item.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    public function checkUpdates(bool $force = false): array
    {
        if (!$force && Cache::has(self::CACHE_KEY)) {
            return $this->getAvailableUpdates();
        }

        $items = $this->localItems();
        $updates = [];

        if ($items) {
            try {
                $response = Http::withHeaders([
                        'GP247-API-License' => config('gp247-config.env.GP247_API_LICENSE'),
                        'GP247-API-Domain'  => url('/'),
                        'Accept'            => 'application/json',
                    ])
                    ->withOptions(['verify' => $this->verifySsl()])
                    ->timeout(self::CHECK_TIMEOUT)
                    ->post(config('gp247-config.env.GP247_LIBRARY_API').'/check-update', [
                        'gp247_version' => config('gp247.core'),
                        'items'         => array_values($items),
                    ]);

                $body = $response->json();
                if ($response->successful() && ($body['status'] ?? '') === 'success') {
                    foreach (($body['data'] ?? []) as $item) {
                        $type = $item['type'] ?? '';
                        $key = $item['key'] ?? '';
                        $local = $items[$type.'|'.$key] ?? null;
                        // WHY: never trust the server blindly — only keep items that
                        // really are newer than what is installed locally.
                        if ($local && !empty($item['version'])
                            && version_compare($item['version'], $local['version'], '>')) {
                            $updates[$type.'|'.$key] = [
                                'type'                => $type,
                                'key'                 => $key,
                                'version'             => $item['version'],
                                'version_local'       => $local['version'],
                                'path'                => $item['path'] ?? '',
                                'checksum_sha256'     => $item['checksum_sha256'] ?? '',
                                'link'                => $item['link'] ?? '',
                                'require_update_from' => $item['require_update_from'] ?? '',
                                'require_license'     => (bool) ($item['require_license'] ?? false),
                                'license_reason'      => $item['license_reason'] ?? 'none',
                                'license_expire_at'   => $item['license_expire_at'] ?? null,
                            ];
                            // Sync license status from the check verdict, but only
                            // when a license was actually sent (else 'required' from
                            // an un-entered key would clobber nothing meaningful).
                            if (!empty($item['require_license']) && !empty($local['license'])) {
                                $this->syncLicenseStatus($type, $key, $item['license_reason'] ?? 'none', $item['license_expire_at'] ?? null);
                            }
                        }
                    }
                } else {
                    gp247_report(msg: 'Check-update API error: '.json_encode($body ?? $response->body()), channel: null);
                }
            } catch (\Throwable $e) {
                gp247_report(msg: 'Check-update error: '.$e->getMessage(), channel: null);
            }
        }

        $ttl = (int) config('gp247-config.admin.extension.update_check_ttl', 21600);
        Cache::put(self::CACHE_KEY, $updates, $ttl);

        return $updates;
    }

    /**
     * Apply an available update for one extension: backup, download, validate,
     * replace files, then run the AppConfig::update() hook. Any failure after
     * live files were replaced triggers a restore from the backup.
     *
     * @param string $type 'Plugins' or 'Templates'.
     * @param string $key  Extension key (folder name / configKey).
     * @return array ['error' => 0|1, 'msg' => string]
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    public function update(string $type, string $key): array
    {
        $type = ($type === 'Templates') ? 'Templates' : 'Plugins';

        $item = $this->getAvailableUpdates()[$type.'|'.$key]
            ?? $this->checkUpdates(true)[$type.'|'.$key]
            ?? null;
        if (!$item) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.update_not_found', ['key' => $key])];
        }

        $appPath = 'GP247/'.$type.'/'.$key;
        $localConfigFile = app_path($appPath.'/gp247.json');
        if (!file_exists($localConfigFile)) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.update_not_found', ['key' => $key])];
        }
        $oldVersion = json_decode(file_get_contents($localConfigFile), true)['version'] ?? '0.0.0';

        // Block a too-old current version before downloading (server advertised
        // this constraint via require_update_from; the zip manifest is re-checked
        // below as the authoritative gate).
        $requireUpdateFrom = $item['require_update_from'] ?? '';
        if ($requireUpdateFrom && version_compare($oldVersion, $requireUpdateFrom, '<')) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.update_from_too_low', ['from' => $requireUpdateFrom, 'local' => $oldVersion])];
        }
        if (empty($item['path'])) {
            // Paid extension without a usable download path: either it needs a
            // per-plugin license (entitlement failed) or it is a manual-only paid item.
            if (!empty($item['require_license'])) {
                $reason = $item['license_reason'] ?? 'required';
                $this->syncLicenseStatus($type, $key, $reason, $item['license_expire_at'] ?? null);
                return ['error' => 1, 'msg' => $this->licenseMessage($reason, $key, $item)];
            }
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.update_paid_manual', ['key' => $key])];
        }

        foreach ([public_path('GP247/'.$type), app_path('GP247/'.$type), storage_path('tmp')] as $checkPath) {
            if (!is_writable($checkPath)) {
                $msg = 'No write permission '.$checkPath;
                gp247_report(msg: $msg, channel: null);
                return ['error' => 1, 'msg' => $msg];
            }
        }

        $pathTmp = storage_path('tmp/update_'.$key.'_'.time());
        try {
            // Download + unzip + validate everything BEFORE touching live files
            $zipFile = $this->download($item, $pathTmp);
            $unzipDir = $pathTmp.'/unzip';
            if (!gp247_unzip($zipFile, $unzipDir)) {
                throw new \Exception(gp247_language_render('admin.extension.error_unzip'));
            }
            $checkConfig = glob($unzipDir.'/*/gp247.json');
            if (!$checkConfig) {
                throw new \Exception('Cannot found file gp247.json');
            }
            $config = json_decode(file_get_contents($checkConfig[0]), true);
            if (($config['configKey'] ?? '') !== $key) {
                throw new \Exception('Config key mismatch: '.($config['configKey'] ?? ''));
            }
            $newVersion = $config['version'] ?? '';
            if (!$newVersion || !version_compare($newVersion, $oldVersion, '>')) {
                throw new \Exception(gp247_language_render('admin.extension.update_not_newer', ['version' => $newVersion ?: '?', 'local' => $oldVersion]));
            }
            // Authoritative minimum-from-version gate: read from the package's own
            // manifest so it holds even if the server did not advertise it.
            $manifestUpdateFrom = $config['requireUpdateFrom'] ?? '';
            if ($manifestUpdateFrom && version_compare($oldVersion, $manifestUpdateFrom, '<')) {
                throw new \Exception(gp247_language_render('admin.extension.update_from_too_low', ['from' => $manifestUpdateFrom, 'local' => $oldVersion]));
            }
            $requireFaild = gp247_extension_check_compatibility($config);
            if ($requireFaild) {
                throw new \Exception(gp247_language_render('admin.extension.not_compatible', ['msg' => json_encode($requireFaild)]));
            }
            $folderNew = dirname($checkConfig[0]);
        } catch (\Throwable $e) {
            File::deleteDirectory($pathTmp);
            gp247_report(msg: 'Extension update error ('.$key.'): '.$e->getMessage(), channel: null);
            return ['error' => 1, 'msg' => $e->getMessage()];
        }

        $backupPath = '';
        try {
            $backupPath = $this->backup($type, $key, $oldVersion);
        } catch (\Throwable $e) {
            File::deleteDirectory($pathTmp);
            gp247_report(msg: 'Extension backup error ('.$key.'): '.$e->getMessage(), channel: null);
            return ['error' => 1, 'msg' => $e->getMessage()];
        }

        // Danger zone: live files are being replaced from here on
        try {
            File::deleteDirectory(app_path($appPath));
            File::deleteDirectory(public_path($appPath));
            File::copyDirectory($folderNew.'/public', public_path($appPath));
            File::copyDirectory($folderNew, app_path($appPath));

            $namespace = gp247_extension_get_namespace(type: $type, key: $key).'\AppConfig';
            if (!class_exists($namespace)) {
                throw new \Exception('Class not found');
            }
            $response = (new $namespace)->update($oldVersion);
            if (!is_array($response) || ($response['error'] ?? 1) != 0) {
                throw new \Exception($response['msg'] ?? 'Update hook failed');
            }
        } catch (\Throwable $e) {
            $this->restore($backupPath, $type, $key);
            File::deleteDirectory($pathTmp);
            gp247_report(msg: 'Extension update rolled back ('.$key.'): '.$e->getMessage(), channel: null);
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.update_rollback', ['msg' => $e->getMessage()])];
        }

        File::deleteDirectory($pathTmp);
        $this->pruneBackups($type, $key);
        $this->forgetUpdate($type, $key);
        gp247_extension_after_update();

        // A successful paid update means the server accepted the license — record
        // the valid status (server-truth) so a stale/edited flag self-heals.
        if (!empty($item['require_license'])) {
            $this->syncLicenseStatus($type, $key, 'none', $item['license_expire_at'] ?? null);
        }

        return [
            'error' => 0,
            'msg' => gp247_language_render('admin.extension.update_success', ['key' => $key, 'version' => $newVersion]),
        ];
    }

    /**
     * Verify a per-plugin license against the marketplace at entry time.
     *
     * Unlike the update-time check, no target version is sent — the server just
     * confirms the key belongs to this plugin and reports the covered expiry.
     * Degrades softly: an unreachable server yields checked=false (unverified).
     *
     * @param string $type    'Plugins' or 'Templates'.
     * @param string $key     Extension key.
     * @param string $license License key entered by the admin.
     * @return array{valid:bool, reason:string, expire:?string, checked:bool}
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     * @aidlc-adr plugin-manager_per-plugin-license
     */
    public function verifyLicense(string $type, string $key, string $license): array
    {
        if (trim($license) === '') {
            return ['valid' => false, 'reason' => 'required', 'expire' => null, 'checked' => true];
        }
        try {
            $response = Http::withHeaders([
                    'GP247-API-License' => config('gp247-config.env.GP247_API_LICENSE'),
                    'GP247-API-Domain'  => url('/'),
                    'Accept'            => 'application/json',
                ])
                ->withOptions(['verify' => $this->verifySsl()])
                ->timeout(self::CHECK_TIMEOUT)
                ->post(config('gp247-config.env.GP247_LIBRARY_API').'/license/validate', [
                    'key'     => $key,
                    'license' => $license,
                ]);

            $body = $response->json();
            if ($response->successful() && ($body['status'] ?? '') === 'success') {
                return [
                    'valid'   => (bool) ($body['allowed'] ?? false),
                    'reason'  => $body['reason'] ?? 'invalid',
                    'expire'  => $body['expire_at'] ?? null,
                    'checked' => true,
                ];
            }
            gp247_report(msg: 'License verify API error: '.json_encode($body ?? $response->body()), channel: null);
        } catch (\Throwable $e) {
            gp247_report(msg: 'License verify error: '.$e->getMessage(), channel: null);
        }
        return ['valid' => false, 'reason' => 'unverified', 'expire' => null, 'checked' => false];
    }

    /**
     * Whether to verify the marketplace API's TLS certificate.
     *
     * Safe default (true) keeps NFR-SEC-extension-update-integrity in production;
     * a local/dev marketplace with a self-signed certificate can opt out via
     * GP247_UPDATE_VERIFY_SSL=false.
     *
     * @return bool
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    protected function verifySsl(): bool
    {
        return (bool) config('gp247-config.admin.extension.update_verify_ssl', true);
    }

    /**
     * Sync the per-plugin license status in admin_config from an authoritative
     * server reason.
     *
     * The stored status is only a cache of server-truth (the site could have
     * edited it, or it could be stale), so any API verdict — a denial OR a
     * success (`none`) — overwrites it. No-op when no license row exists (nothing
     * to attach the status to).
     *
     * @param string      $type   'Plugins' or 'Templates'.
     * @param string      $key    Extension key.
     * @param string      $reason required|invalid|version|expired|none.
     * @param string|null $expire Expiry from the server, when known.
     * @return void
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     * @aidlc-adr plugin-manager_per-plugin-license
     */
    protected function syncLicenseStatus(string $type, string $key, string $reason, ?string $expire = null): void
    {
        if (!function_exists('gp247_extension_set_license_status')) {
            return;
        }
        gp247_extension_set_license_status($type, $key, [
            'valid'   => $reason === 'none',
            'reason'  => $reason,
            'expire'  => $expire,
            'checked' => true,
        ]);
    }

    /**
     * Build the list of locally installed extensions with their versions,
     * read from each extension's gp247.json manifest.
     *
     * @return array Map "<Type>|<key>" => ['type' =>, 'key' =>, 'version' =>].
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    protected function localItems(): array
    {
        $items = [];
        foreach (['Plugins', 'Templates'] as $type) {
            foreach (gp247_extension_get_all_local(type: $type) as $key => $namespace) {
                $configFile = app_path('GP247/'.$type.'/'.$key.'/gp247.json');
                if (!file_exists($configFile)) {
                    continue;
                }
                $version = json_decode(file_get_contents($configFile), true)['version'] ?? '';
                if ($version) {
                    $entry = ['type' => $type, 'key' => $key, 'version' => $version];
                    // Send the per-plugin license (paid extensions) so the server
                    // can return entitlement state for the UI.
                    $license = gp247_extension_get_license($type, $key);
                    if ($license) {
                        $entry['license'] = $license;
                    }
                    $items[$type.'|'.$key] = $entry;
                }
            }
        }
        return $items;
    }

    /**
     * Download the update zip over HTTPS (SSL verification enabled by default)
     * and verify its sha256 checksum when the API provided one.
     *
     * @param array  $item    Update item from checkUpdates().
     * @param string $pathTmp Absolute temp directory for this update run.
     * @return string Absolute path of the downloaded zip file.
     * @throws \Exception When the download fails or the checksum mismatches.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    protected function download(array $item, string $pathTmp): string
    {
        $url = $item['path'];
        // Paid extensions: append the per-plugin license so the extension/download
        // endpoint can check entitlement before proxying (NFR-SEC-plugin-license-entitlement).
        if (!empty($item['require_license'])) {
            $license = gp247_extension_get_license($item['type'], $item['key']);
            if ($license) {
                $url .= (strpos($url, '?') === false ? '?' : '&').'license='.urlencode($license);
            }
        }

        $response = Http::withHeaders([
                'GP247-API-License' => config('gp247-config.env.GP247_API_LICENSE'),
                'GP247-API-Domain'  => url('/'),
            ])
            ->withOptions(['verify' => $this->verifySsl()])
            ->timeout(self::DOWNLOAD_TIMEOUT)
            ->get($url);

        if (!$response->successful()) {
            throw new \Exception('Download failed: HTTP '.$response->status());
        }
        $data = $response->body();

        // The download endpoint answers HTTP 200 with a JSON body on failure
        $jsonData = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($jsonData['error']) && $jsonData['error'] == 1) {
            // Map per-plugin license denials to friendly messages, and sync the
            // authoritative reason into admin_config (cache of server-truth).
            if (in_array($jsonData['detail'] ?? '', ['required', 'invalid', 'version', 'expired', 'domain'], true)) {
                $this->syncLicenseStatus($item['type'], $item['key'], $jsonData['detail'], $item['license_expire_at'] ?? null);
                throw new \Exception($this->licenseMessage($jsonData['detail'], $item['key'], $item));
            }
            $detail = isset($jsonData['detail']) ? ' - '.$jsonData['detail'] : '';
            throw new \Exception(($jsonData['msg'] ?? 'Download error').$detail);
        }

        if (!empty($item['checksum_sha256']) && !hash_equals($item['checksum_sha256'], hash('sha256', $data))) {
            throw new \Exception(gp247_language_render('admin.extension.update_checksum_failed'));
        }

        File::ensureDirectoryExists($pathTmp);
        $zipFile = $pathTmp.'/'.$item['key'].'.zip';
        if (file_put_contents($zipFile, $data) === false) {
            throw new \Exception('Cannot write file '.$zipFile);
        }
        return $zipFile;
    }

    /**
     * Build the admin-facing message for a per-plugin license denial reason.
     *
     * @param string $reason required|invalid|version|expired.
     * @param string $key    Extension key.
     * @param array  $item   Update item (for expiry / link context).
     * @return string Localized message.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     * @aidlc-adr plugin-manager_per-plugin-license
     */
    protected function licenseMessage(string $reason, string $key, array $item = []): string
    {
        switch ($reason) {
            case 'version':
                return gp247_language_render('admin.extension.license_invalid_version', ['key' => $key, 'version' => $item['version'] ?? '']);
            case 'expired':
                return gp247_language_render('admin.extension.license_expired', ['key' => $key, 'date' => $item['license_expire_at'] ?? '']);
            case 'invalid':
                return gp247_language_render('admin.extension.license_invalid', ['key' => $key]);
            case 'domain':
                return gp247_language_render('admin.extension.license_domain', ['key' => $key]);
            case 'required':
            default:
                return gp247_language_render('admin.extension.license_required', ['key' => $key]);
        }
    }

    /**
     * Copy the extension's app/ and public/ directories into
     * storage/backups/extensions before live files are replaced.
     *
     * @param string $type       'Plugins' or 'Templates'.
     * @param string $key        Extension key.
     * @param string $oldVersion Currently installed version (used in folder name).
     * @return string Absolute path of the created backup directory.
     * @throws \Exception When the backup cannot be written.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    protected function backup(string $type, string $key, string $oldVersion): string
    {
        $appPath = 'GP247/'.$type.'/'.$key;
        $backupPath = $this->backupRoot($type, $key).'/'.$oldVersion.'_'.time();
        File::ensureDirectoryExists($backupPath.'/app');
        File::ensureDirectoryExists($backupPath.'/public');
        if (!File::copyDirectory(app_path($appPath), $backupPath.'/app')) {
            throw new \Exception('Cannot backup '.app_path($appPath));
        }
        if (is_dir(public_path($appPath)) && !File::copyDirectory(public_path($appPath), $backupPath.'/public')) {
            throw new \Exception('Cannot backup '.public_path($appPath));
        }
        return $backupPath;
    }

    /**
     * Restore an extension's app/ and public/ directories from a backup
     * created by backup(). Used to roll back a failed update.
     *
     * @param string $backupPath Absolute backup directory path.
     * @param string $type       'Plugins' or 'Templates'.
     * @param string $key        Extension key.
     * @return bool True when the restore succeeded.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    public function restore(string $backupPath, string $type, string $key): bool
    {
        try {
            $appPath = 'GP247/'.$type.'/'.$key;
            File::deleteDirectory(app_path($appPath));
            File::deleteDirectory(public_path($appPath));
            File::copyDirectory($backupPath.'/app', app_path($appPath));
            if (is_dir($backupPath.'/public')) {
                File::copyDirectory($backupPath.'/public', public_path($appPath));
            }
            return true;
        } catch (\Throwable $e) {
            gp247_report(msg: 'Extension restore error ('.$key.'): '.$e->getMessage().' — backup kept at '.$backupPath, channel: null);
            return false;
        }
    }

    /**
     * Keep only the newest BACKUP_KEEP backups for one extension.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    protected function pruneBackups(string $type, string $key): void
    {
        try {
            $dirs = File::directories($this->backupRoot($type, $key));
            // WHY: folder names end with the unix timestamp, so a plain sort
            // puts the oldest backups first for the same version prefix; sort by
            // mtime instead to be correct across version renames.
            usort($dirs, fn ($a, $b) => filemtime($a) <=> filemtime($b));
            foreach (array_slice($dirs, 0, max(0, count($dirs) - self::BACKUP_KEEP)) as $dir) {
                File::deleteDirectory($dir);
            }
        } catch (\Throwable $e) {
            gp247_report(msg: 'Extension backup prune error ('.$key.'): '.$e->getMessage(), channel: null);
        }
    }

    /**
     * Root backup directory for one extension.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    protected function backupRoot(string $type, string $key): string
    {
        return storage_path('backups/extensions/'.$type.'/'.$key);
    }

    /**
     * Drop one applied update from the cached update map so the badge
     * disappears immediately without waiting for the cache TTL.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    protected function forgetUpdate(string $type, string $key): void
    {
        $updates = $this->getAvailableUpdates();
        if (isset($updates[$type.'|'.$key])) {
            unset($updates[$type.'|'.$key]);
            $ttl = (int) config('gp247-config.admin.extension.update_check_ttl', 21600);
            Cache::put(self::CACHE_KEY, $updates, $ttl);
        }
    }
}
