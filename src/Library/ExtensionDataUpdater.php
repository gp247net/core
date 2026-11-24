<?php

namespace GP247\Core\Library;

/**
 * Runs the data hook (AppConfig::update()) of extensions whose files were replaced
 * OUTSIDE the marketplace flow — git pull, composer, FTP, a manual copy.
 *
 * The marketplace flow knows the previous version because it reads gp247.json before
 * overwriting the files. After a plain file replacement that trace is gone, so core
 * keeps its own record of the installed version (admin_config, code "extension_version")
 * and compares it with the manifest on disk. Applying is idempotent: the new version is
 * recorded only after the hook succeeded, so a failed hook can simply be retried.
 *
 * Runs synchronously — no queue, no cron — so it works on shared hosting (NFR-AVAIL-001).
 *
 * Before each hook the extension's public/ folder is copied again (files copied over app/
 * bring new static files too), and a library update left pending by ExtensionUpdateManager
 * (marker) is finished here — restoring its backup when the hook fails.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-local-update-data-converge, US-PLG-005
 * @aidlc-adr plugin-manager_local-update-data-converge
 * @aidlc-adr plugin-manager_extension-update-fresh-process
 * @aidlc-adr plugin-manager_orphan-forget-registration
 */
class ExtensionDataUpdater
{
    public const TYPES = ['Plugins', 'Templates'];

    /**
     * Extensions whose manifest is newer than (or unknown to) the recorded version.
     *
     * Read-only: used by the admin badge, gp247:doctor and --dry-run.
     *
     * @param string|null $type Plugins|Templates, or null for both.
     * @return array<string, array{type: string, key: string, from: ?string, to: string}> Keyed by "<type>|<key>".
     */
    public function pending(?string $type = null): array
    {
        $pending = [];
        foreach ($this->candidates($type) as $item) {
            if ($this->state($item['from'], $item['to']) === 'pending') {
                $pending[$item['type'].'|'.$item['key']] = $item;
            }
        }

        return $pending;
    }

    /**
     * Apply the data hook of every installed extension whose files are newer than the
     * recorded version (or whose version was never recorded — see state()).
     *
     * @param string|null $type   Plugins|Templates, or null for both.
     * @param string|null $key    One extension key, or null for every extension of the type(s).
     * @param bool        $dryRun List what would be applied without calling any hook.
     * @return array<int, array{type: string, key: string, from: ?string, to: string, status: string, msg: string}>
     *         status: applied | pending (dry-run) | skipped | failed.
     */
    public function applyPending(?string $type = null, ?string $key = null, bool $dryRun = false): array
    {
        $results = [];
        $applied = 0;

        foreach ($this->candidates($type) as $item) {
            if ($key !== null && $item['key'] !== $key) {
                continue;
            }

            $marker = $this->pendingLibraryUpdate($item, $dryRun);
            $state = $this->state($item['from'], $item['to']);
            if ($state === 'in-sync') {
                if ($marker !== null && !$dryRun) {
                    // The hook already ran and the version was recorded; only the clean-up was missed.
                    $this->manager()->settlePending($item['type'], $item['key'], $marker, true);
                }
                $results[] = $item + ['status' => 'skipped', 'msg' => 'up to date'];
                continue;
            }
            if ($state === 'downgrade') {
                gp247_report(msg: 'Extension data update skipped for '.$item['type'].'/'.$item['key'].': files are '.$item['to'].' but '.$item['from'].' was installed (downgrade)', channel: null);
                $results[] = $item + ['status' => 'skipped', 'msg' => 'files are older than the installed version'];
                continue;
            }
            $from = $marker !== null ? $marker['from'] : $item['from'];
            $belowFloor = $this->belowFloor($from, $item['floor']);
            if ($belowFloor !== null) {
                gp247_report(msg: 'Extension data update skipped for '.$item['type'].'/'.$item['key'].': '.$belowFloor, channel: null);
                $results[] = $item + ['status' => 'skipped', 'msg' => $belowFloor];
                continue;
            }
            if ($dryRun) {
                $results[] = $item + ['status' => 'pending', 'msg' => 'would run update('.($item['from'] ?? 'null').')'];
                continue;
            }

            // WHY before the hook: files copied over app/ bring new CSS/JS/images that only
            // reach the browser through public/; the hook may also rely on them being there.
            $published = (new ExtensionInstaller)->publishAssets($item['type'], $item['key']);
            if (($published['error'] ?? 1) != 0) {
                $results[] = $item + ['status' => 'failed', 'msg' => (string) $published['msg']];
                continue;
            }

            $response = $this->runHook($item['type'], $item['key'], $from);
            if (($response['error'] ?? 1) != 0) {
                $msg = (string) ($response['msg'] ?? 'Update hook failed');
                gp247_report(msg: 'Extension data update failed for '.$item['type'].'/'.$item['key'].' ('.($from ?? 'unknown').' -> '.$item['to'].'): '.$msg, channel: null);
                if ($marker !== null) {
                    $this->manager()->settlePending($item['type'], $item['key'], $marker, false, $msg);
                }
                $results[] = $item + ['status' => 'failed', 'msg' => $msg];
                continue;
            }

            gp247_extension_set_installed_version($item['type'], $item['key'], $item['to']);
            if ($marker !== null) {
                $this->manager()->settlePending($item['type'], $item['key'], $marker, true);
            }
            $applied++;
            $results[] = $item + ['status' => 'applied', 'msg' => (string) ($response['msg'] ?? '')];
        }

        if ($applied > 0) {
            gp247_extension_after_update();
        }

        return $results;
    }

    /**
     * Marker of a library update still waiting for its data hook, when it matches the files
     * on disk. A marker for another version is stale (the files were replaced again since):
     * it is dropped — never restored — except during a dry run, which changes nothing.
     *
     * @param array{type: string, key: string, from: ?string, to: string} $item
     * @param bool $dryRun
     * @return array{from: ?string, to: string, backup: string, at: string}|null
     */
    private function pendingLibraryUpdate(array $item, bool $dryRun): ?array
    {
        $marker = ExtensionPendingUpdate::read($item['type'], $item['key']);
        if ($marker === null) {
            return null;
        }
        if ($marker['to'] !== $item['to']) {
            if (!$dryRun) {
                ExtensionPendingUpdate::forget($item['type'], $item['key']);
            }

            return null;
        }

        return $marker;
    }

    /**
     * @return ExtensionUpdateManager
     */
    private function manager(): ExtensionUpdateManager
    {
        return app(ExtensionUpdateManager::class);
    }

    /**
     * Installed extensions (flag row in the database) that still have their source on disk,
     * with the recorded version and the manifest version.
     *
     * @param string|null $type
     * @return array<int, array{type: string, key: string, from: ?string, to: string, floor: string}>
     */
    private function candidates(?string $type): array
    {
        $types = $type === null ? self::TYPES : [$type === 'Templates' ? 'Templates' : 'Plugins'];
        $items = [];

        foreach ($types as $t) {
            foreach (gp247_extension_get_installed(type: $t, active: false) as $key => $row) {
                $key = (string) $key;
                if (preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1 || !gp247_extension_source_exists($t, $key)) {
                    continue;
                }
                $manifestFile = app_path('GP247/'.$t.'/'.$key.'/gp247.json');
                if (!is_file($manifestFile)) {
                    continue;
                }
                $manifest = json_decode((string) file_get_contents($manifestFile), true);
                $to = trim((string) ($manifest['version'] ?? ''));
                if ($to === '') {
                    continue;
                }
                $items[] = [
                    'type' => $t,
                    'key' => $key,
                    'from' => gp247_extension_installed_version($t, $key),
                    'to' => $to,
                    'floor' => gp247_extension_min_update_from(is_array($manifest) ? $manifest : []),
                ];
            }
        }

        return $items;
    }

    /**
     * Why the recorded data is too old for the files on disk, or null when it may be updated.
     *
     * Same floor as a library update (gp247.json "minUpdateFrom"): the files of a much newer
     * release — copied over an orphan, for instance — must not run their hook on data they
     * do not support. A site that never recorded its version keeps rule 1a (no floor).
     *
     * @param string|null $from  Recorded data version.
     * @param string      $floor Minimum version the manifest can update from ('' = none).
     * @return string|null
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-local-update-data-converge
     */
    private function belowFloor(?string $from, string $floor): ?string
    {
        if ($from === null || $from === '' || $floor === '' || version_compare($from, $floor, '>=')) {
            return null;
        }

        return 'data '.$from.' is older than '.$floor.', the oldest version these files can update from: install '.$floor.' (or a release between) first';
    }

    /**
     * Compare the recorded version with the manifest.
     *
     * WHY null counts as pending: sites installed before core kept this record have no
     * version row; the hook is run once with null (hooks are idempotent by contract) and
     * the manifest version is recorded from then on.
     *
     * @param string|null $installed
     * @param string      $manifest
     * @return string pending | in-sync | downgrade
     */
    private function state(?string $installed, string $manifest): string
    {
        if ($installed === null || $installed === '') {
            return 'pending';
        }
        if (version_compare($manifest, $installed, '>')) {
            return 'pending';
        }
        if (version_compare($manifest, $installed, '<')) {
            return 'downgrade';
        }

        return 'in-sync';
    }

    /**
     * Call the extension's update hook, isolating any exception into an error response.
     *
     * @param string      $type
     * @param string      $key
     * @param string|null $fromVersion
     * @return array{error: int, msg: string}
     */
    private function runHook(string $type, string $key, ?string $fromVersion): array
    {
        try {
            $class = gp247_extension_get_namespace(type: $type, key: $key).'\AppConfig';
            if (!class_exists($class)) {
                return ['error' => 1, 'msg' => 'Class not found: '.$class];
            }
            $instance = new $class;
            if (!method_exists($instance, 'update')) {
                // Plugin format 1.0: a file replacement is the whole update.
                return ['error' => 0, 'msg' => ''];
            }
            $response = $instance->update($fromVersion);

            return is_array($response)
                ? ['error' => (int) ($response['error'] ?? 1), 'msg' => (string) ($response['msg'] ?? '')]
                : ['error' => 1, 'msg' => 'Unexpected update response'];
        } catch (\Throwable $e) {
            return ['error' => 1, 'msg' => $e->getMessage()];
        }
    }
}
