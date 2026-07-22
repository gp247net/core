<?php
namespace GP247\Core\Controllers;

use GP247\Core\Library\ExtensionUpdateManager;
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
        
        // Build API URL with parameters
        $page = request('page') ?? 1;
        $url = config('gp247-config.env.GP247_LIBRARY_API').'/'.strtolower($this->groupType).'?page[size]=20&page[number]='.$page;
        $url .='&version='.$gp247_version;
        $url .='&is_free='.$is_free;
        $url .='&type_sort='.$type_sort;
        $url .='&keyword='.$keyword;

        // Call API to get extensions list
        try {
            // Initialize CURL
            $ch = curl_init($url);
            // Configure CURL options
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return result instead of output
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);  // Timeout after 10s
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // Ignore SSL verify
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); // Ignore SSL verify host
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Allow redirect
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // Maximum number of redirects
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'GP247-API-License: ' . $license,
                'GP247-API-Domain: ' . url('/'),
                'Content-Type: application/json',
                'Accept: application/json'
            ]); // Add license to request headers
            
            // Execute CURL
            $dataApi = curl_exec($ch);

            // Get response information
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            
            // Only log URLs if there is a redirect (final URL is different from original URL)
            if ($finalUrl !== $url) {
                gp247_report(msg: 'Redirect detected:', channel: null);
                gp247_report(msg: '- Original URL: ' . $url, channel: null);
                gp247_report(msg: '- Final URL: ' . $finalUrl, channel: null);
            }

            curl_close($ch);

            // Parse JSON response
            $dataApi = json_decode($dataApi, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON decode error: ' . json_last_error_msg());
            }

        } catch (\Throwable $e) {
            // Log error and set default data if error occurs
            gp247_report(msg: 'API Error: ' . $e->getMessage(), channel: null);
            $dataApi = ['data' => [], 'error' => $e->getMessage()];
        }

        // Process data returned from API
        if (!empty($dataApi['data'])) {
            // Map data to extensions array
            foreach ($dataApi['data'] as $key => $data) {
                $arrExtensions[] = [
                    'sku'             => $data['sku'] ?? '',
                    'key'             => $data['key'] ?? '',
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

        // check error
        $errorCode = '';
        $errorMessage = '';
        if (isset($dataApi['status']) && $dataApi['status'] == 'error') {
            $errorCode = $dataApi['code'] ?? '';
            $errorMessage = $dataApi['message'] ?? '';
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
        $appPath = 'GP247/'.$this->groupType.'/'.$key;

        if (!is_writable(public_path('GP247/'.$this->groupType))) {
            $msg = 'No write permission '.public_path('GP247/'.$this->groupType.'/');
            gp247_report(msg:$msg, channel:null);
            return response()->json(['error' => 1, 'msg' => $msg]);
        }

        if (!is_writable(app_path('GP247/'.$this->groupType.'/'))) {
            $msg = 'No write permission '.app_path('GP247/'.$this->groupType.'/');
            gp247_report(msg:$msg, channel:null);
            return response()->json(['error' => 1, 'msg' => $msg]);
        }

        if (!is_writable(storage_path('tmp'))) {
            $msg = 'No write permission '.storage_path('tmp');
            gp247_report(msg:$msg, channel:null);
            return response()->json(['error' => 1, 'msg' => $msg]);
        }

        try {
            if ($isPaid) {
                // Persist the entered license, then fetch the zip through the
                // license-gated download endpoint (sends API-License header).
                gp247_extension_save_license($this->groupType, $key, $license);
                $data = $this->fetchPaidExtensionZip($key, $license);
            } else {
                $data = file_get_contents($path);
            }

            // Check if response is JSON error instead of zip file
            $jsonData = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($jsonData['error']) && $jsonData['error'] == 1) {
                $errorMsg = $jsonData['msg'] ?? 'Unknown error';
                $detail = $jsonData['detail'] ?? '';
                // Sync the authoritative license verdict into admin_config so the
                // key icon flags the problem (cache of server-truth).
                if ($isPaid && in_array($detail, ['required', 'invalid', 'version', 'expired', 'domain'], true)) {
                    gp247_extension_set_license_status($this->groupType, $key, ['valid' => false, 'reason' => $detail, 'expire' => null, 'checked' => true]);
                    return response()->json(['error' => 1, 'msg' => $this->licenseStatusMessage(['valid' => false, 'reason' => $detail], $key)]);
                }
                $errorDetail = $detail !== '' ? ' - '.$detail : '';
                return response()->json(['error' => 1, 'msg' => $errorMsg . $errorDetail]);
            }

            $pathTmp = $key.'_'.time();
            $fileTmp = $pathTmp.'.zip';
            Storage::disk('tmp')->put($pathTmp.'/'.$fileTmp, $data);
            $unzip = gp247_unzip(storage_path('tmp/'.$pathTmp.'/'.$fileTmp), storage_path('tmp/'.$pathTmp));
            if ($unzip) {
                $checkConfig = glob(storage_path('tmp/'.$pathTmp) . '/*/gp247.json');

                if (!$checkConfig) {
                    $response = ['error' => 1, 'msg' => 'Cannot found file gp247.json'];
                    return response()->json($response);
                }

                //Check compatibility 
                $config = json_decode(file_get_contents($checkConfig[0]), true);
                $requireFaild = gp247_extension_check_compatibility($config);
                if ($requireFaild) {
                    File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                    $response = ['error' => 1, 'msg' => gp247_language_render('admin.extension.not_compatible', ['msg' => json_encode($requireFaild)])];
                } else {
                    $folderName = explode('/gp247.json', $checkConfig[0]);
                    $folderName = explode('/', $folderName[0]);
                    $folderName = end($folderName);
                    File::copyDirectory(storage_path('tmp/'.$pathTmp.'/'.$folderName.'/public'), public_path($appPath));
                    File::copyDirectory(storage_path('tmp/'.$pathTmp.'/'.$folderName), app_path($appPath));
                    File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                    $namespace = gp247_extension_get_namespace(type:$this->groupType, key:$key);
                    $namespace = $namespace . '\AppConfig';
                    //Check class exist
                    if (class_exists($namespace)) {
                        //Check method install exist
                        if (method_exists($namespace, 'install')) {
                            $response = (new $namespace)->install();
                        }else{
                            $msg = 'Method install not found';
                            gp247_report(msg:$msg, channel:null);
                            return response()->json(['error' => 1, 'msg' => $msg]);
                        }
                    } else {
                        $msg = 'Class not found';
                        gp247_report(msg:$msg, channel:null);
                        return response()->json(['error' => 1, 'msg' => $msg]);
                    }
                }

            } else {
                $msg = 'error while unzip';
                gp247_report(msg:$msg, channel:null);
                $response = ['error' => 1, 'msg' => $msg];
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            gp247_report(msg:$msg, channel:null);
            $response = ['error' => 1, 'msg' => $msg];
        }
        if (is_array($response) && $response['error'] == 0) {
            if ($isPaid) {
                // Server accepted the license (the zip was served) — record valid.
                gp247_extension_set_license_status($this->groupType, $key, ['valid' => true, 'reason' => 'none', 'expire' => null, 'checked' => true]);
            }
            gp247_notice_add(type: $this->groupType, typeId: $key, content:'admin.notice.gp247_'.strtolower($this->groupType).'_install::name__'.$key);
            gp247_extension_after_update();
        }

        return response()->json($response);
    }

    /**
     * Fetch a paid extension zip from the license-gated download endpoint.
     *
     * Sends the API-connection license header and the per-plugin license so the
     * server (api-247) can validate entitlement before proxying the private repo.
     * Returns the raw body — either zip bytes or a JSON error (handled by caller).
     *
     * @param string $key     Extension key.
     * @param string $license Per-plugin license.
     * @return string Response body (zip bytes or JSON error).
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     * @aidlc-adr plugin-manager_per-plugin-license
     */
    protected function fetchPaidExtensionZip(string $key, string $license): string
    {
        $url = config('gp247-config.env.GP247_LIBRARY_API').'/extension/download'
            .'?type='.rawurlencode($this->groupType)
            .'&key='.rawurlencode($key)
            .'&gp247_version='.rawurlencode((string) config('gp247.core'))
            .'&license='.rawurlencode($license);

        $response = \Illuminate\Support\Facades\Http::withHeaders([
                'GP247-API-License' => config('gp247-config.env.GP247_API_LICENSE'),
                'GP247-API-Domain'  => url('/'),
            ])
            ->withOptions(['verify' => (bool) config('gp247-config.admin.extension.update_verify_ssl', true)])
            ->timeout(120)
            ->get($url);

        if (!$response->successful()) {
            return json_encode(['error' => 1, 'msg' => 'Download failed: HTTP '.$response->status()]);
        }
        return $response->body();
    }

    public function registerLicense()
    {
        $url = config('gp247-config.env.GP247_LIBRARY_API').'/register-license';
        try {
            // Initialize CURL
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \Exception('Failed to initialize CURL');
            }

            // Configure CURL options
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return result instead of output
            curl_setopt($ch, CURLOPT_POST, true); // Set POST method
            curl_setopt($ch, CURLOPT_POSTFIELDS, request()->all()); // Send all request data
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);  // Timeout after 10s
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // Ignore SSL verify
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); // Ignore SSL verify host
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Allow redirect
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // Maximum number of redirects
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'GP247-API-Domain: ' . url('/'),
                'Content-Type: application/json',
                'Accept: application/json'
            ]); 
            
            // Execute CURL
            $dataApi = curl_exec($ch);
            
            // Debug request headers
            $requestHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);
            gp247_report(msg: 'Request headers: ' . $requestHeaders, channel: null);
            
            // Check for CURL errors
            if ($dataApi === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new \Exception('CURL Error: ' . $error);
            }

            // Get response information
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            
            // Only log URLs if there is a redirect (final URL is different from original URL)
            if ($finalUrl !== $url) {
                gp247_report(msg: 'Redirect detected:', channel: null);
                gp247_report(msg: '- Original URL: ' . $url, channel: null);
                gp247_report(msg: '- Final URL: ' . $finalUrl, channel: null);
            }

            curl_close($ch);
            // Parse JSON response
            $data = json_decode($dataApi, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON decode error: ' . json_last_error_msg());
            }

            $dataResponse = [
                'status' => $data['status'] ?? 'error',
                'message' => $data['message'] ?? 'Unknown error',
                'data' => $data['data'] ?? null
            ];

        } catch (\Throwable $e) {
            // Log error and return error response
            gp247_report(msg: 'API Register Error: ' . $e->getMessage(), channel: null);
            
            $dataResponse =  [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => null
            ];
        }

        if ($dataResponse['status'] == 'success') {
            $license = $dataResponse['data']['license'] ?? '';
            
            // Check if .env file exists
            if (!file_exists(base_path('.env'))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'File .env not found'
                ]);
            }

            // Read .env content
            $envContent = file_get_contents(base_path('.env'));
            
            // Check if GP247_API_LICENSE exists
            if (strpos($envContent, 'GP247_API_LICENSE') === false) {
                if (substr($envContent, -1) !== "\n") {
                    $envContent .= "\n";
                }
                $envContent .= "GP247_API_LICENSE=" . $license . "\n";
            } else {
                $envContent = preg_replace(
                    '/GP247_API_LICENSE=.*/',
                    'GP247_API_LICENSE=' . $license,
                    $envContent
                );
            }
            
            try {
                file_put_contents(base_path('.env'), $envContent);
                return response()->json([
                    'status' => 'success',
                    'message' => 'License registered successfully'
                ]);
            } catch (\Throwable $e) {
                $msg = 'GP247_API_LICENSE='.$license;
                return response()->json([
                    'status' => 'error',
                    'message' =>  gp247_language_render('admin.extension.error_write_env', ['msg' => $msg])
                ]);
            }
        }

        return response()->json($dataResponse);
    }
}
