<?php
namespace GP247\Core\Controllers;

use GP247\Core\Library\ExtensionInstaller;
use GP247\Core\Library\ExtensionUpdateManager;
use GP247\Core\Library\LibraryClient;
use GP247\Core\Library\LicenseRegistrar;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

trait ExtensionOnlineController
{
    /**
     * Online extension library: one page of the marketplace listing for this extension group.
     *
     * Asks for the whole catalogue by default (`compat=all`): extensions that need another
     * core are listed and flagged `compatible = false` (an older marketplace omits the flag
     * and returns only compatible items, so a missing flag means compatible). On the
     * Plugins tab the picked filter groups are sent as raw code lists and the chip counts
     * come from the marketplace's per-code counts; without them the screen falls back to
     * filtering and counting the current page client-side.
     *
     * @return \Illuminate\Contracts\View\View
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-002
     * @aidlc-story US-PLG-online-catalog-all-cores
     * @aidlc-story US-PLG-config-code-filter
     * @aidlc-adr plugin-manager_online-catalog-all-cores
     * @aidlc-adr plugin-manager_config-code-filter
     */
    public function index()
    {
        $filters = $this->onlineFilters();
        [$codeIn, $codeNot] = gp247_plugin_config_group_api_filter($filters['group']);

        // Fetch the listing through the shared marketplace client (uniform
        // headers/SSL/timeout, soft-degrades to an empty list on error).
        $params = [
            'page[size]'   => 20,
            'page[number]' => max(1, (int) request('page', 1)),
            'version'      => config('gp247.core'),
            'is_free'      => $filters['is_free'],
            'type_sort'    => $filters['type_sort'],
            'keyword'      => $filters['keyword'],
            'compat'       => $filters['compat'],
        ];
        if ($codeIn) {
            $params['code'] = $codeIn;
        }
        if ($codeNot) {
            $params['code_not'] = $codeNot;
        }
        $dataApi = (new LibraryClient)->list($this->groupType, $params);

        $arrExtensions = array_map([$this, 'mapOnlineItem'], is_array($dataApi['data'] ?? null) ? $dataApi['data'] : []);

        // WHY: the page numbers come from the remote marketplace API and end up in HTML;
        // cast them so nothing but a number reaches the markup.
        $currentPage = (int) ($dataApi['current_page'] ?? 1);
        $lastPage = (int) ($dataApi['last_page'] ?? 1);
        $resultItems = '';
        if ($arrExtensions) {
            $resultItems = gp247_language_render('admin.result_item', [
                'item_from' => (int) ($dataApi['from'] ?? 0),
                'item_to' => (int) ($dataApi['to'] ?? 0),
                'total' => (int) ($dataApi['total'] ?? 0),
            ]);
        }

        // Library-wide counts per filter group when the marketplace sends them
        // (US-PLG-config-code-filter); otherwise the view counts the current page.
        $libraryCounts = $dataApi['meta']['code_counts'] ?? null;
        $groupCounts = is_array($libraryCounts) ? gp247_plugin_config_group_counts($libraryCounts) : null;

        // Surface a marketplace API failure in the admin banner. LibraryClient
        // normalizes any non-2xx/exception into a payload carrying a non-empty
        // 'error' plus (when the API sent them) 'code'/'message'. Key off 'error'
        // — not the former string status=='error' — because 'status' is now the
        // HTTP status code (int) under the shared contract, so the old check would
        // never match and the banner silently vanished
        // (RISK-TECH-cli-marketplace-error-swallow).
        $errorCode = '';
        $errorMessage = '';
        if (!empty($dataApi['error'])) {
            $errorCode = $dataApi['code'] ?? (string) ($dataApi['status'] ?? 'error');
            $errorMessage = $dataApi['message'] ?? $dataApi['error'];
        }

        $title = gp247_language_render('admin.extension.management', ['extension' => $this->groupType]);

        // Updates already discovered for this extension group ("<Type>|<key>" map)
        $arrUpdates = (new ExtensionUpdateManager)->checkUpdates();

        return view('gp247-admin::screen.extension_online')->with(
            [
                    "title"              => $title,
                    "groupType"          => $this->groupType,
                    "arrExtensionsLocal" => gp247_extension_get_all_local(type: $this->groupType),
                    // Installed in the database but source missing (e.g. database cloned from
                    // another site): shown a "source missing" note instead of Install (US-PLG-orphan-source-missing-notice).
                    "arrOrphans"         => (new ExtensionInstaller)->orphans($this->groupType),
                    "arrExtensions"      => $arrExtensions,
                    "arrUpdates"         => $arrUpdates,
                    "keyword"            => $filters['keyword'],
                    "type_sort"          => $filters['type_sort'],
                    "is_free"            => $filters['is_free'],
                    "compat"             => $filters['compat'],
                    "groups"             => $filters['group'],
                    "groupCounts"        => $groupCounts,
                    "groupTotal"         => is_array($libraryCounts) ? array_sum($groupCounts) : null,
                    "filterQuery"        => $this->onlineFilterQuery($filters),
                    "currentPage"        => $currentPage,
                    "lastPage"           => $lastPage,
                    "pages"              => gp247_extension_pagination_pages($currentPage, $lastPage),
                    "resultItems"        => $resultItems,
                    "dataApi"            => $dataApi,
                    "urlAction"          => $this->onlineUrlActions(),
                    "errorCode"          => $errorCode,
                    "errorMessage"       => $errorMessage,
                ]
        );
    }

    /**
     * Filters of the online library read from the query string, each checked against an
     * allowlist so nothing unexpected reaches the marketplace or the page.
     *
     * @return array{keyword: string, is_free: int, type_sort: string, compat: string, group: string[]}
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-online-catalog-all-cores
     * @aidlc-story US-PLG-config-code-filter
     */
    protected function onlineFilters(): array
    {
        $typeSort = (string) request('type_sort', '');
        $groups = request('group', []);
        $groups = ($this->groupType === 'Plugins' && is_array($groups))
            ? array_values(array_intersect(array_keys(gp247_plugin_config_group_labels()), $groups))
            : [];

        return [
            'keyword'   => mb_substr(trim((string) (is_string(request('keyword')) ? request('keyword') : '')), 0, 100),
            'is_free'   => request('is_free') == 1 ? 1 : 0,
            'type_sort' => in_array($typeSort, ['download', 'rating', 'sort_price_asc', 'sort_price_desc'], true) ? $typeSort : '',
            'compat'    => request('compat') === 'compatible' ? 'compatible' : 'all',
            'group'     => $groups,
        ];
    }

    /**
     * The filters as they go back into the screen's links (pager, group chips): empty
     * values and the default compat are left out so links stay short.
     *
     * @param array $filters Result of onlineFilters().
     * @return array Query parameters (no page).
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-002
     */
    protected function onlineFilterQuery(array $filters): array
    {
        $query = array_filter([
            'keyword'   => $filters['keyword'],
            'is_free'   => $filters['is_free'] ?: null,
            'type_sort' => $filters['type_sort'],
            'compat'    => $filters['compat'] === 'all' ? null : $filters['compat'],
        ], fn ($value) => $value !== null && $value !== '');
        if ($filters['group']) {
            $query['group'] = $filters['group'];
        }

        return $query;
    }

    /**
     * Normalize one marketplace listing item to the fields the screen reads.
     *
     * @param array $data Item from the marketplace.
     * @return array
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-online-catalog-all-cores
     */
    protected function mapOnlineItem($data): array
    {
        $data = is_array($data) ? $data : [];

        return [
            'sku'             => $data['sku'] ?? '',
            'key'             => $data['key'] ?? '',
            // Defensive: an older marketplace may not send a configCode. When absent the
            // plugin falls into the "Other" filter bucket (US-PLG-config-code-filter).
            'code'            => $data['code'] ?? '',
            'name'            => $data['name'] ?? '',
            'description'     => $data['description'] ?? '',
            'image'           => $data['image'] ?? '',
            'image_demo'      => $data['image_demo'] ?? '',
            'path'            => $data['path'] ?? '',
            'file'            => $data['file'] ?? '',
            'version'         => $data['version'] ?? '',
            'gp247_version'   => $data['gp247_version'] ?? '',
            // An older marketplace sends no flag and only items this core can run.
            'compatible'      => array_key_exists('compatible', $data) ? (bool) $data['compatible'] : true,
            'price'           => $data['price'] ?? 0,
            'price_final'     => $data['price_final'] ?? 0,
            'price_promotion' => $data['price_promotion'] ?? 0,
            'is_free'         => $data['is_free'] ?? 0,
            'download'        => $data['download'] ?? 0,
            'username'        => $data['username'] ?? '',
            'times'           => $data['times'] ?? 0,
            'points'          => $data['points'] ?? 0,
            'rated'           => $data['rated'] ?? 0,
            'date'            => $data['date'] ?? '',
            'link'            => $data['link'] ?? '',
        ];
    }

    /**
     * Action URLs of the online screen for this extension group.
     *
     * @return array<string, string>
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-002
     */
    protected function onlineUrlActions(): array
    {
        $prefix = $this->groupType === 'Templates' ? 'admin_template' : 'admin_plugin';

        return [
            'install'     => gp247_route_admin($prefix . '_online.install'),
            'local'       => gp247_route_admin($prefix . '.index'),
            'urlImport'   => gp247_route_admin($prefix . '.import'),
            'update'      => gp247_route_admin($prefix . '_online.update'),
            'checkUpdate' => gp247_route_admin($prefix . '_online.check-update'),
            'saveLicense' => gp247_route_admin($prefix . '_online.save-license'),
        ];
    }

    /**
     * Apply an available update for one installed extension (1-click update).
     * The download URL is resolved server-side from the cached check-update
     * result — the client only sends the extension key.
     *
     * @return \Illuminate\Http\JsonResponse ['error' => 0|1, 'msg' => string]
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    public function update()
    {
        $key = request('key');
        if (!$key || !array_key_exists($key, gp247_extension_get_all_local(type: $this->groupType))) {
            return response()->json(['error' => 1, 'msg' => gp247_language_render('admin.extension.update_not_found', ['key' => (string) $key])]);
        }

        // WHY a plain boolean flag: the browser resends it only after the person confirmed
        // "continue without a snapshot" (US-PLG-pre-change-hook).
        $response = (new ExtensionUpdateManager)->update($this->groupType, $key, request()->boolean('skip_pre_change'));

        if (($response['error'] ?? 1) == 0) {
            gp247_notice_add(type: $this->groupType, typeId: $key, content: 'admin.notice.gp247_'.strtolower($this->groupType).'_update::name__'.$key);
        }

        return response()->json($response);
    }

    /**
     * Force a fresh check-update API call (bypass cache) and report how many
     * updates are available for this extension group.
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    public function checkUpdate()
    {
        $updates = (new ExtensionUpdateManager)->checkUpdates(force: true);
        $countGroup = count(array_filter($updates, fn ($item) => ($item['type'] ?? '') === $this->groupType));

        return response()->json([
            'error' => 0,
            'count' => $countGroup,
            'msg' => $countGroup
                ? gp247_language_render('admin.extension.update_found', ['count' => $countGroup])
                : gp247_language_render('admin.extension.update_none'),
        ]);
    }

    /**
     * Persist the per-plugin license entered for a paid extension.
     *
     * The license is stored per plugin (admin_config group 'ExtensionLicense'),
     * distinct from the API-connection license (GP247_API_LICENSE). The cached
     * update map is dropped so the next check re-evaluates entitlement.
     *
     * @return \Illuminate\Http\JsonResponse ['error' => 0|1, 'msg' => string]
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     * @aidlc-adr plugin-manager_per-plugin-license
     */
    public function saveLicense()
    {
        // Accept any non-empty key: paid items can have their license entered
        // BEFORE install (first-time paid install), not only when already local.
        $key = request('key');
        if (!$key) {
            return response()->json(['error' => 1, 'msg' => gp247_language_render('admin.extension.update_not_found', ['key' => (string) $key])]);
        }

        $license = trim((string) request('license', ''));

        // Empty input removes the stored license record entirely.
        if ($license === '') {
            gp247_extension_delete_license($this->groupType, $key);
            \Illuminate\Support\Facades\Cache::forget('gp247_extension_updates');
            return response()->json(['error' => 0, 'valid' => false, 'msg' => gp247_language_render('admin.extension.license_saved', ['key' => $key])]);
        }

        // Verify the key BEFORE persisting it: a key the server affirms invalid is
        // NOT saved, and any previously stored (valid / manually-set) license is
        // left untouched. A verified-valid key — or one that could not be verified
        // (server unreachable) — is saved.
        $status = (new ExtensionUpdateManager)->verifyLicense($this->groupType, $key, $license);
        if (($status['checked'] ?? false) && !($status['valid'] ?? false)) {
            return response()->json(['error' => 1, 'valid' => false, 'msg' => $this->licenseStatusMessage($status, $key)]);
        }

        gp247_extension_save_license($this->groupType, $key, $license);
        gp247_extension_set_license_status($this->groupType, $key, $status);

        // Drop the cached check-update map so entitlement is re-evaluated next time.
        \Illuminate\Support\Facades\Cache::forget('gp247_extension_updates');

        return response()->json([
            'error' => 0,
            'valid' => $status['valid'],
            'msg' => $this->licenseStatusMessage($status, $key),
        ]);
    }

    /**
     * Build the admin message describing a just-verified license status.
     *
     * @param array  $status Result from ExtensionUpdateManager::verifyLicense().
     * @param string $key    Extension key.
     * @return string Localized message.
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     * @aidlc-adr plugin-manager_per-plugin-license
     */
    protected function licenseStatusMessage(array $status, string $key): string
    {
        if (!empty($status['valid'])) {
            return gp247_language_render('admin.extension.license_saved', ['key' => $key]);
        }
        switch ($status['reason'] ?? '') {
            case 'expired':
                return gp247_language_render('admin.extension.license_expired', ['key' => $key, 'date' => $status['expire'] ?? '']);
            case 'invalid':
                return gp247_language_render('admin.extension.license_invalid', ['key' => $key]);
            case 'domain':
                return gp247_language_render('admin.extension.license_domain', ['key' => $key]);
            case 'unverified':
                return gp247_language_render('admin.extension.license_unverified', ['key' => $key]);
            default: // required / empty — treated as cleared
                return gp247_language_render('admin.extension.license_saved', ['key' => $key]);
        }
    }

    public function install()
    {
        // Edge validation (US-PLG-002, ADR plugin-manager_install-input-trust-boundary):
        // the key names a folder and a license record; arrays (key[]=…) are refused too.
        $key = request()->input('key');
        $path = request()->input('path', '');
        if (!is_string($key) || !ExtensionInstaller::isValidKey($key) || !is_string($path)) {
            return response()->json(['error' => 1, 'msg' => 'Invalid extension key', 'detail' => 'invalid_key']);
        }
        // Paid items (first-time install) are fetched from the license-gated
        // extension/download endpoint instead of the public filev3 path.
        $isPaid = request('paid') == 1;
        $license = (string) request('license', '');

        // Refuse an installed or orphaned extension before any write — including the
        // license row an orphan brought along with a cloned database.
        $refusal = (new ExtensionInstaller)->installedRefusal($this->groupType, $key);
        if ($refusal !== null) {
            return response()->json($refusal);
        }

        if ($isPaid) {
            // Persist the entered license before the gated download attempt.
            gp247_extension_save_license($this->groupType, $key, $license);
        }

        // Shared engine: fetch (free path / paid gated endpoint) → unzip → verify
        // → copy → install — the exact same path the CLI uses.
        $response = (new ExtensionInstaller)->installFromRemote($this->groupType, $key, [
            'path'    => $path,
            'paid'    => $isPaid,
            'license' => $license,
        ]);

        // Sync the authoritative license verdict into admin_config (cache of
        // server-truth) so the key icon reflects entitlement.
        if ($isPaid) {
            if (is_array($response) && ($response['error'] ?? 1) == 1) {
                $detail = $response['detail'] ?? '';
                if (in_array($detail, ['required', 'invalid', 'version', 'expired', 'domain'], true)) {
                    $expire = $response['expire'] ?? null;
                    gp247_extension_set_license_status($this->groupType, $key, ['valid' => false, 'reason' => $detail, 'expire' => $expire, 'checked' => true]);
                    return response()->json(['error' => 1, 'msg' => $this->licenseStatusMessage(['valid' => false, 'reason' => $detail, 'expire' => $expire], $key)]);
                }
            } elseif (is_array($response) && ($response['error'] ?? 1) == 0) {
                // Server accepted the license (the zip was served) — record valid.
                gp247_extension_set_license_status($this->groupType, $key, ['valid' => true, 'reason' => 'none', 'expire' => null, 'checked' => true]);
            }
        }

        if (is_array($response) && ($response['error'] ?? 1) == 0) {
            gp247_notice_add(type: $this->groupType, typeId: $key, content:'admin.notice.gp247_'.strtolower($this->groupType).'_install::name__'.$key);
        }

        return response()->json($response);
    }

    /**
     * Register this domain's API-connection license (the "Click here" button).
     *
     * Shares LicenseRegistrar with the CLI (gp247:ext-register-license) so both
     * surfaces register + persist the license through one code-path
     * (NFR-SEC-cli-service-parity). The .env write logic lives in the service,
     * not here.
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-register-license
     * @aidlc-adr system-cli_service-extraction
     */
    public function registerLicense()
    {
        $result = (new LicenseRegistrar)->register();

        if ($result['status'] === 'success' && empty($result['wrote_env'])) {
            // .env not writable: surface the token so the admin can paste it.
            $msg = 'GP247_API_LICENSE=' . ($result['license'] ?? '');
            return response()->json([
                'status'  => 'error',
                'message' => gp247_language_render('admin.extension.error_write_env', ['msg' => $msg]),
            ]);
        }

        return response()->json([
            'status'  => $result['status'],
            'message' => $result['message'],
        ]);
    }
}
