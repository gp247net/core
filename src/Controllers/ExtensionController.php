<?php
namespace GP247\Core\Controllers;

use GP247\Core\Library\ExtensionInstaller;
use Illuminate\Support\Facades\File;

trait  ExtensionController
{
    const MAX_FILE_SIZE = 50; // 50MB

    public function index()
    {
        $action = request('action');
        $key = request('key');
        if ($action == 'config' && $key != '') {
            $namespace = gp247_extension_get_namespace(type:$this->groupType, key:$key);
            $namespace = $namespace . '\AppConfig';
            if (class_exists($namespace)) {
                $body = (new $namespace)->clickApp();
            } else {
                $body = ['error' => 1, 'msg' => 'Class not found'];
            }
        } else {
            $body = $this->render();
        }
        return $body;
    }

    protected function render()
    {
        $extensionProtected = config('gp247-config.admin.extension.extension_protected')[$this->groupType] ?? [];
        $extensionsInstalled = gp247_extension_get_installed(type:$this->groupType, active: false);
        $extensions = gp247_extension_get_all_local(type: $this->groupType);

        $listUrlAction = $this->listUrlAction;

        // WHY: cache only — the local screen must never block on the marketplace API
        $arrUpdates = (new \GP247\Core\Library\ExtensionUpdateManager)->getAvailableUpdates();

        // Per-store enable context (Plugins only). On a multi-store/marketplace site the
        // root admin can pick a store and toggle each storeScope=store plugin on/off for
        // just that store; single-store sites see no selector and behave exactly as before
        // (US-PLG-per-store-plugin-enable-list, NFR-MAINT-store-scope-single-mechanism).
        $perStoreEnable = $this->groupType === 'Plugins'
            && function_exists('gp247_store_check_multi_domain_installed')
            && gp247_store_check_multi_domain_installed();
        $storeList = $perStoreEnable ? \GP247\Core\Models\AdminStore::getListTitle() : [];
        $selectedStoreId = '';
        if ($perStoreEnable) {
            $req = (string) request('store_id', '');
            // Only honour a store the admin actually owns; anything else = GLOBAL view.
            if ($req !== '' && array_key_exists($req, $storeList)) {
                $selectedStoreId = $req;
            }
        }

        return view('gp247-admin::screen.extension')->with(
            [
                "title"               => gp247_language_render('admin.extension.management', ['extension' => $this->groupType]),
                "groupType"           => $this->groupType,
                "configExtension"     => config('gp247-config.admin.api_'.strtolower($this->groupType)),
                "extensionsInstalled" => $extensionsInstalled,
                "extensions"          => $extensions,
                "extensionProtected"  => $extensionProtected,
                "listUrlAction"       => $listUrlAction,
                "arrUpdates"          => $arrUpdates,
                "perStoreEnable"      => $perStoreEnable,
                "storeList"           => $storeList,
                "selectedStoreId"     => $selectedStoreId,
            ]
        );
    }

    /**
     * Whether an enable/disable request targets a single store (the per-store toggle on
     * the list) rather than the system-wide flag. True only for a Plugins request that
     * carries a valid store_id, on a multi-store site, for an installed storeScope=store
     * plugin — otherwise the caller falls back to the global path (or reports an error
     * when a store_id was given but is not eligible).
     *
     * @param string $key     Plugin key.
     * @param mixed  $storeId Requested store id (empty ⇒ global request).
     * @return bool
     */
    protected function isPerStoreEnableRequest(string $key, $storeId): bool
    {
        if ($this->groupType !== 'Plugins' || $storeId === null || (string) $storeId === '') {
            return false;
        }
        if (!function_exists('gp247_store_check_multi_domain_installed') || !gp247_store_check_multi_domain_installed()) {
            return false;
        }
        if (gp247_extension_scope('Plugins', $key) !== 'store') {
            return false;
        }
        if (!\GP247\Core\Models\AdminStore::where('id', $storeId)->exists()) {
            return false;
        }
        // The plugin must be installed system-wide before a store can override its state.
        return gp247_extension_check_installed('Plugins', $key);
    }

    /**
     * Install extension
     */
    public function install()
    {
        $key = request('key');
        // Delegate the compat-check + AppConfig install() + cache refresh to the
        // shared installer so CLI and UI stay in lock-step (ADR system-cli_service-extraction).
        $response = (new ExtensionInstaller)->activate($this->groupType, $key);
        if (is_array($response) && ($response['error'] ?? 1) == 0) {
            gp247_notice_add(type:$this->groupType, typeId: $key, content:'admin.notice.gp247_'.strtolower($this->groupType).'_install::name__'.$key);
        }
        return response()->json($response);
    }

    /**
     * Uninstall plugin
     *
     * @return  [type]  [return description]
     */
    public function uninstall()
    {
        $key = request('key');
        $onlyRemoveData = (bool) request('onlyRemoveData');

        // Guards (extension_protected + template-in-use) are enforced inside the
        // shared installer so both UI and CLI honor them (ADR system-cli_service-extraction).
        $response = (new ExtensionInstaller)->uninstall($this->groupType, $key, $onlyRemoveData);
        if (is_array($response) && ($response['error'] ?? 1) == 0) {
            gp247_notice_add(type:$this->groupType, typeId: $key, content:'admin.notice.gp247_'.strtolower($this->groupType).'_uninstall::name__'.$key);
        }
        return response()->json($response);
    }

    /**
     * Enable plugin
     *
     * @return  [type]  [return description]
     */
    public function enable()
    {
        $key = request('key');
        $storeId = request('store_id');

        // Per-store toggle: flip only this store's override row, leaving the system-wide
        // flag (and install state) untouched (US-PLG-per-store-plugin-enable-list).
        if ((string) $storeId !== '') {
            if (!$this->isPerStoreEnableRequest($key, $storeId)) {
                return response()->json(['error' => 1, 'msg' => 'Invalid per-store enable request']);
            }
            gp247_plugin_store_enable_set($key, $storeId, true);
            return response()->json(['error' => 0, 'msg' => gp247_language_render('admin.msg_change_success')]);
        }

        $response = (new ExtensionInstaller)->enable($this->groupType, $key);
        if (is_array($response) && ($response['error'] ?? 1) == 0) {
            gp247_notice_add(type:$this->groupType, typeId: $key, content:'admin.notice.gp247_'.strtolower($this->groupType).'_enable::name__'.$key);
        }
        return response()->json($response);
    }

    /**
     * Disable plugin
     *
     * @return  [type]  [return description]
     */
    public function disable()
    {
        $key = request('key');
        $storeId = request('store_id');

        // Per-store toggle: flip only this store's override row (US-PLG-per-store-plugin-enable-list).
        if ((string) $storeId !== '') {
            if (!$this->isPerStoreEnableRequest($key, $storeId)) {
                return response()->json(['error' => 1, 'msg' => 'Invalid per-store disable request']);
            }
            gp247_plugin_store_enable_set($key, $storeId, false);
            return response()->json(['error' => 0, 'msg' => gp247_language_render('admin.msg_change_success')]);
        }

        // Guards (template-in-use) are enforced inside the shared installer.
        $response = (new ExtensionInstaller)->disable($this->groupType, $key);
        if (is_array($response) && ($response['error'] ?? 1) == 0) {
            gp247_notice_add(type: $this->groupType, typeId: $key, content:'admin.notice.gp247_'.strtolower($this->groupType).'_disable::name__'.$key);
        }
        return response()->json($response);
    }

    /**
     * Import plugin
     */
    public function importExtension()
    {
        if (strtolower($this->groupType) == 'templates') {
            $urlAction = gp247_route_admin('admin_template.process_import');
        } else {
            $urlAction = gp247_route_admin('admin_plugin.process_import');
        }
        
        // Calculate server limits for upload
        $uploadMaxFilesize = ini_get('upload_max_filesize');
        $postMaxSize = ini_get('post_max_size');
        $maxSizeInMB = min(gp247_getMaximumFileUploadSize('M'), self::MAX_FILE_SIZE);
        $maxSizeInBytes = gp247_convertPHPSizeToBytes($postMaxSize);
        $uploadMaxBytes = gp247_convertPHPSizeToBytes($uploadMaxFilesize);
        
        $data =  [
            'title' => gp247_language_render('admin.extension.import').': '.$this->groupType,
            'urlAction' => $urlAction,
            'uploadMaxFilesize' => $uploadMaxFilesize,
            'postMaxSize' => $postMaxSize,
            'maxSizeInMB' => number_format($maxSizeInMB, 2),
            'maxSizeInBytes' => min($uploadMaxBytes, $maxSizeInBytes, self::MAX_FILE_SIZE * 1024 * 1024), // 50MB
            'listUrlAction' => $this->listUrlAction,
            'configExtension' => config('gp247-config.admin.api_'.strtolower($this->groupType)),
        ];
        return view('gp247-admin::screen.extension_upload')
        ->with($data);
    }

    /**
     * Process import
     *
     * @return  [type]  [return description]
     */
    public function processImport()
    {
        // Handle case when POST data exceeds post_max_size (PHP rejects entire request)
        // When this happens, PHP sets $_POST and $_FILES to empty arrays
        // but CONTENT_LENGTH header still contains the actual request size
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
        
        if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
            $postMaxSize = ini_get('post_max_size');
            $uploadMaxSize = ini_get('upload_max_filesize');
            
            $msg = sprintf(
                'Upload rejected by server: File size (%s) exceeds post_max_size limit (%s). ' .
                'Current server limits: upload_max_filesize=%s, post_max_size=%s. ' .
                'Please choose a smaller file (max %s) or contact administrator to increase server limits.',
                number_format($contentLength / 1048576, 2) . ' MB',
                $postMaxSize,
                $uploadMaxSize,
                $postMaxSize,
                number_format(min(gp247_getMaximumFileUploadSize('M'), self::MAX_FILE_SIZE), 2) . ' MB'
            );
            return redirect()->back()->with('error', $msg);
        }
        
        $data = request()->all();
        
        // Check if file uploaded successfully before validation
        if (request()->hasFile('file')) {
            $uploadedFile = request()->file('file');
            if (!$uploadedFile->isValid()) {
                $errorCode = $uploadedFile->getError();
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE   => 'File size exceeds upload_max_filesize (' . ini_get('upload_max_filesize') . ') in php.ini',
                    UPLOAD_ERR_FORM_SIZE  => 'File size exceeds MAX_FILE_SIZE directive in HTML form',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
                    UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload',
                ];
                $errorMsg = $errorMessages[$errorCode] ?? 'Unknown upload error (code: ' . $errorCode . ')';
                return redirect()->back()->with('error', 'Upload failed: ' . $errorMsg);
            }
        }
        
        // Calculate max upload size allowed (min between PHP config and 50MB limit)
        $maxSizeConfig = gp247_getMaximumFileUploadSize($unit = 'K');
        $maxAllowed = min($maxSizeConfig, self::MAX_FILE_SIZE * 1024); // 50MB in KB
        
        $validator = \Validator::make(
            $data,
            [
                // Use 'mimes:zip' instead of 'mimetypes' for better compatibility across OS
                'file' => 'required|file|mimes:zip|max:' . $maxAllowed,
            ],
            [
                'file.required' => 'Please select a file to upload',
                'file.mimes'    => 'File must be a ZIP archive',
                'file.max'      => 'File size must not exceed ' . number_format($maxAllowed / 1024, 2) . ' MB (current limit: upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size') . ')',
            ]
        );

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        if (!is_writable(storage_path('tmp'))) {
            $msg = 'No write permission '.storage_path('tmp');
            gp247_report(msg:$msg, channel:null);
            return redirect()->back()->with('error', $msg);
        }

        $pathTmp = time();
        $dataFile = gp247_file_upload($data['file'], $disk = 'tmp', $pathFolder = $pathTmp);
        if (($dataFile['error'] ?? 1) != 0) {
            return redirect()->back()->with('error', 'Import extension error: '.($dataFile['msg'] ?? ''));
        }

        $zipPath = storage_path('tmp/'.($dataFile['data']['pathFile'] ?? ''));

        // Shared engine: unzip → verify manifest → copy → install — the exact same
        // path the CLI uses (ADR system-cli_service-extraction).
        $response = (new ExtensionInstaller)->installFromZip($this->groupType, $zipPath);

        // Remove the uploaded archive (installFromZip cleans its own extract dir).
        @File::delete($zipPath);

        if (!is_array($response) || ($response['error'] ?? 1) == 1) {
            return redirect()->back()->with('error', $response['msg'] ?? 'Import extension error');
        }

        $configKey = $response['key'] ?? '';
        gp247_notice_add(type:$this->groupType, typeId: $configKey, content:'admin.notice.gp247_'.strtolower($this->groupType).'_import::name__'.$configKey);

        return redirect($this->listUrlAction['urlLocal'] ?? route('admin_plugin.index'))
            ->with('success', gp247_language_render('admin.extension.import_success'));
    }
}
