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
            ]
        );
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
