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
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-local-update-data-converge
 * @aidlc-adr plugin-manager_local-update-data-converge
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

            $state = $this->state($item['from'], $item['to']);
            if ($state === 'in-sync') {
                $results[] = $item + ['status' => 'skipped', 'msg' => 'up to date'];
                continue;
            }
            if ($state === 'downgrade') {
                gp247_report(msg: 'Extension data update skipped for '.$item['type'].'/'.$item['key'].': files are '.$item['to'].' but '.$item['from'].' was installed (downgrade)', channel: null);
                $results[] = $item + ['status' => 'skipped', 'msg' => 'files are older than the installed version'];
                continue;
            }
            if ($dryRun) {
                $results[] = $item + ['status' => 'pending', 'msg' => 'would run update('.($item['from'] ?? 'null').')'];
                continue;
            }

            $response = $this->runHook($item['type'], $item['key'], $item['from']);
            if (($response['error'] ?? 1) != 0) {
                $msg = (string) ($response['msg'] ?? 'Update hook failed');
                gp247_report(msg: 'Extension data update failed for '.$item['type'].'/'.$item['key'].' ('.($item['from'] ?? 'unknown').' -> '.$item['to'].'): '.$msg, channel: null);
                $results[] = $item + ['status' => 'failed', 'msg' => $msg];
                continue;
            }

            gp247_extension_set_installed_version($item['type'], $item['key'], $item['to']);
            $applied++;
            $results[] = $item + ['status' => 'applied', 'msg' => (string) ($response['msg'] ?? '')];
        }

        if ($applied > 0) {
            gp247_extension_after_update();
        }

        return $results;
    }

    /**
     * Installed extensions (flag row in the database) that still have their source on disk,
     * with the recorded version and the manifest version.
     *
     * @param string|null $type
     * @return array<int, array{type: string, key: string, from: ?string, to: string}>
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
                ];
            }
        }

        return $items;
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
