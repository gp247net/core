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
    public function index()
    {
        $license = config('gp247-config.env.GP247_API_LICENSE');
        // Initialize required variables
        $arrExtensions = [];  // Array containing list of extensions
        $resultItems = '';    // String to display search results
        $htmlPaging = '';     // HTML pagination
        
        // Get parameters from request
        $gp247_version = config('gp247.core');  // Core version
        $is_free = request('is_free', 0);  // Filter free extensions
        $type_sort = request('type_sort', ''); // Filter by type
        $keyword = request('keyword', ''); // Search keyword
        
        // Fetch the listing through the shared marketplace client (uniform
        // headers/SSL/timeout, soft-degrades to an empty list on error).
        $page = request('page') ?? 1;
        $dataApi = (new LibraryClient)->list($this->groupType, [
            'page[size]'   => 20,
            'page[number]' => $page,
            'version'      => $gp247_version,
            'is_free'      => $is_free,
            'type_sort'    => $type_sort,
            'keyword'      => $keyword,
        ]);

        // Process data returned from API
        if (!empty($dataApi['data'])) {
            // Map data to extensions array
            foreach ($dataApi['data'] as $key => $data) {
                $arrExtensions[] = [
                    'sku'             => $data['sku'] ?? '',
                    'key'             => $data['key'] ?? '',
                    // Defensive: the marketplace API may not send a configCode yet. When
                    // absent the plugin falls into the "Other" filter bucket on the online
                    // tab (US-PLG-config-code-filter); it starts filtering by category the
                    // moment the API returns 'code'. No behaviour change when empty.
                    'code'            => $data['code'] ?? '',
                    'name'            => $data['name'] ?? '',
                    'description'     => $data['description'] ?? '',
                    'image'           => $data['image'] ?? '',
                    'image_demo'      => $data['image_demo'] ?? '',
                    'path'            => $data['path'] ?? '',
                    'file'            => $data['file'] ?? '',
                    'version'         => $data['version'] ?? '',
                    'gp247_version'   => $data['gp247_version'] ?? '',
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
            
            // Create pagination HTML
            $resultItems = gp247_language_render('admin.result_item', [
                'item_from' => $dataApi['from'] ?? 0, 
                'item_to' => $dataApi['to']??0, 
                'total' =>  $dataApi['total'] ?? 0
            ]);
            
            // Build pagination HTML
            $htmlPaging .= '<ul class="pagination pagination-sm no-margin pull-right">';
            if ($dataApi['current_page'] > 1) {
                $htmlPaging .= '<li class="page-item"><a class="page-link" href="'.$this->urlOnline.'?page='.($dataApi['current_page'] - 1).'" rel="prev">«</a></li>';
            } else {
                for ($i = 1; $i < $dataApi['last_page']; $i++) {
                    if ($dataApi['current_page'] == $i) {
                        $htmlPaging .= '<li class="page-item active"><span class="page-link">'.$i.'</span></li>';
                    } else {
                        $htmlPaging .= '<li class="page-item"><a class="page-link" href="'.$this->urlOnline.'?page='.$i.'">'.$i.'</a></li>';
                    }
                }
            }
            if ($dataApi['current_page'] < $dataApi['last_page']) {
                $htmlPaging .= '<li class="page-item"><a class="page-link" href="'.$this->urlOnline.'?page='.($dataApi['current_page'] + 1).'" rel="next">»</a></li>';
            }
            $htmlPaging .= '</ul>';
        }

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

        switch ($this->groupType) {
            case 'Templates':
                $urlAction = [
                    'install' => gp247_route_admin('admin_template_online.install'),
                    'local' => gp247_route_admin('admin_template.index'),
                    'urlImport' => gp247_route_admin('admin_template.import'),
                    'update' => gp247_route_admin('admin_template_online.update'),
                    'checkUpdate' => gp247_route_admin('admin_template_online.check-update'),
                    'saveLicense' => gp247_route_admin('admin_template_online.save-license'),
                ];
                break;

            default:
                $urlAction = [
                    'install' => gp247_route_admin('admin_plugin_online.install'),
                    'local' => gp247_route_admin('admin_plugin.index'),
                    'urlImport' => gp247_route_admin('admin_plugin.import'),
                    'update' => gp247_route_admin('admin_plugin_online.update'),
                    'checkUpdate' => gp247_route_admin('admin_plugin_online.check-update'),
                    'saveLicense' => gp247_route_admin('admin_plugin_online.save-license'),
                ];
                break;
        }

        // Updates already discovered for this extension group ("<Type>|<key>" map)
        $arrUpdates = (new ExtensionUpdateManager)->checkUpdates();

        return view('gp247-admin::screen.extension_online')->with(
            [
                    "title"              => $title,
                    "groupType"          => $this->groupType,
                    "arrExtensionsLocal" => gp247_extension_get_all_local(type: $this->groupType),
                    "arrExtensions"      => $arrExtensions,
                    "arrUpdates"         => $arrUpdates,
                    "keyword"            => $keyword ?? '',
                    "type_sort"          => $type_sort ?? '',
                    "is_free"            => $is_free ?? '',
                    "resultItems"        => $resultItems,
                    "htmlPaging"         => $htmlPaging,
                    "dataApi"            => $dataApi,
                    "urlAction"          => $urlAction,
                    "errorCode"          => $errorCode,
                    "errorMessage"       => $errorMessage,
                ]
        );
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

        $response = (new ExtensionUpdateManager)->update($this->groupType, $key);

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
        $key = request('key');
        $path = request('path');
        // Paid items (first-time install) are fetched from the license-gated
        // extension/download endpoint instead of the public filev3 path.
        $isPaid = request('paid') == 1;
        $license = (string) request('license', '');

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
