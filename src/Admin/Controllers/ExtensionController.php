<?php
namespace GP247\Core\Admin\Controllers;

use Illuminate\Support\Facades\File;
use GP247\Core\Admin\Models\AdminStore;

trait  ExtensionController
{
    public function index()
    {
        $action = request('action');
        $key = request('key');
        if ($action == 'config' && $key != '') {
            $namespace = gp247_extension_get_class_config(type:$this->type, key:$key);
            $body = (new $namespace)->clickApp();
        } else {
            $body = $this->render();
        }
        return $body;
    }

    protected function render()
    {
        $extensionProtected = config('gp247-config.admin.extension.extension_protected')[$this->groupType] ?? [];
        $extensionsInstalled = gp247_extension_get_installed(type:$this->type, active: false);
        $extensions = gp247_extension_get_all_local(type: $this->type);

        $listUrlAction = $this->listUrlAction;

        return view('gp247-core::screen.extension')->with(
            [
                "title"               => gp247_language_render('admin.extension.management', ['extension' => $this->type]),
                "groupType"           => $this->groupType,
                "configExtension"     => config('gp247-config.admin.api_'.strtolower($this->type)),
                "extensionsInstalled" => $extensionsInstalled,
                "extensions"          => $extensions,
                "extensionProtected"  => $extensionProtected,
                "listUrlAction"       => $listUrlAction,
            ]
        );
    }

    /**
     * Install extension
     */
    public function install()
    {
        $key = request('key');
        $namespace = gp247_extension_get_class_config(type:$this->type, key:$key);
        $response = (new $namespace)->install();
        if (is_array($response) && $response['error'] == 0) {
            gp247_notice_add(type:$this->type, typeId: $key, content:'admin_notice.gp247_'.strtolower($this->type).'_install::name__'.$key);
            gp247_extension_update();
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
        $onlyRemoveData = request('onlyRemoveData');

        $this->processUninstall($key);

        $namespace = gp247_extension_get_class_config(type:$this->type, key:$key);
        $extensionsInstalled = gp247_extension_get_installed(type:$this->type, active: false);
        // Check class exist and extension installed
        if (class_exists($namespace) && array_key_exists($key, $extensionsInstalled->toArray())) {
            $response = (new $namespace)->uninstall();
            if (is_array($response) && $response['error'] == 0) {
                gp247_notice_add(type:$this->type, typeId: $key, content:'admin_notice.gp247_'.strtolower($this->type).'_uninstall::name__'.$key);
                gp247_extension_update();
            }
        } else {
            // If extension not yet installed
            $response = ['error' => 0, 'msg' => 'Class not found'];
        }
        if (!$onlyRemoveData) {
            $appPath = 'GP247/'.$this->groupType.'/'.$key;
            // Delete all (include data and source code)
            File::deleteDirectory(app_path($appPath));
            File::deleteDirectory(public_path($appPath));
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
        $namespace = gp247_extension_get_class_config(type:$this->type, key:$key);
        $response = (new $namespace)->enable();
        if (is_array($response) && $response['error'] == 0) {
            gp247_notice_add(type:$this->type, typeId: $key, content:'admin_notice.gp247_'.strtolower($this->type).'_enable::name__'.$key);
            gp247_extension_update();
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

        $this->processDisable($key);

        $namespace = gp247_extension_get_class_config(type:$this->type, key:$key);
        $response = (new $namespace)->disable();
        if (is_array($response) && $response['error'] == 0) {
            gp247_notice_add(type: $this->type, typeId: $key, content:'admin_notice.gp247_'.strtolower($this->type).'_disable::name__'.$key);
            gp247_extension_update();
        }
        return response()->json($response);
    }

    /**
     * Import plugin
     */
    public function importExtension()
    {
        $data =  [
            'title' => gp247_language_render('admin.extension.import'),
            'urlAction' => gp247_route_admin('admin_'.strtolower($this->type).'.process_import')
        ];
        return view('gp247-core::screen.extension_upload')
        ->with($data);
    }

    /**
     * Process import
     *
     * @return  [type]  [return description]
     */
    public function processImport()
    {
        $data = request()->all();
        $validator = \Validator::make(
            $data,
            [
                'file'   => 'required|mimetypes:application/zip|max:'.min($maxSizeConfig = gp247_getMaximumFileUploadSize($unit = 'K'), 51200),
            ]
        );

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        $pathTmp = time();
        $linkRedirect = '';
        $pathFile = gp247_file_upload($data['file'], $disk = 'tmp', $pathFolder = $pathTmp)['pathFile'] ?? '';

        if (!is_writable(storage_path('tmp'))) {
            $msg = 'No write permission '.storage_path('tmp');
            gp247_report(msg:$msg, channel:null);
            return response()->json(['error' => 1, 'msg' => $msg]);
        }
        
        if ($pathFile) {
            $unzip = gp247_unzip(storage_path('tmp/'.$pathFile), storage_path('tmp/'.$pathTmp));
            if ($unzip) {
                $checkConfig = glob(storage_path('tmp/'.$pathTmp) . '/*/gp247.json');
                if ($checkConfig) {
                    $folderName = explode('/gp247.json', $checkConfig[0]);
                    $folderName = explode('/', $folderName[0]);
                    $folderName = end($folderName);
                    
                    //Check compatibility 
                    $config = json_decode(file_get_contents($checkConfig[0]), true);
                    $gp247Version = $config['gp247Version'] ?? '';
                    if (!gp247_extension_check_compatibility($gp247Version)) {
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        return redirect()->back()->with('error', gp247_language_render('admin.extension.not_compatible', ['version' => $gp247Version, 'gp247_version' => config('gp247.core')]));
                    }

                    $configGroup = $config['configGroup'] ?? '';
                    $configKey = $config['configKey'] ?? '';

                    //Process if extention config incorect
                    if (!$configGroup || !$configKey) {
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        return redirect()->back()->with('error', gp247_language_render('admin.extension.error_config_format'));
                    }
                    //Check extension exist
                    $arrPluginLocal = gp247_extension_get_all_local(type: $this->type);
                    if (array_key_exists($configKey, $arrPluginLocal)) {
                        $msg = gp247_language_render('admin.extension.error_exist');
                        gp247_report(msg:$msg, channel:null);
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        return redirect()->back()->with('error', $msg);
                    }

                    $appPath = 'GP247/'.$configGroup.'/'.$configKey;

                    if (!is_writable($checkPubPath = public_path('GP247/'.$configGroup))) {
                        $msg = 'No write permission '.$checkPubPath;
                        gp247_report(msg:$msg, channel:null);
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        return redirect()->back()->with('error', $msg);
                    }
            
                    if (!is_writable($checkAppPath = app_path('GP247/'.$configGroup))) {
                        $msg = 'No write permission '.$checkAppPath;
                        gp247_report(msg:$msg, channel:null);
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        return redirect()->back()->with('error', $msg);
                    }

                    try {
                        File::copyDirectory(storage_path('tmp/'.$pathTmp.'/'.$folderName.'/public'), public_path($appPath));
                        File::copyDirectory(storage_path('tmp/'.$pathTmp.'/'.$folderName), app_path($appPath));
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        $namespace = gp247_extension_get_class_config(type:$this->type, key:$configKey);
                        $response = (new $namespace)->install();
                        if (!is_array($response) || $response['error'] == 1) {
                            $msg = $response['msg'];
                            gp247_report(msg:$msg, channel:null);
                            return redirect()->back()->with('error', $msg);
                        }
                        $linkRedirect = route('admin_plugin.index');
                    } catch (\Throwable $e) {
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        $msg = $e->getMessage();
                        gp247_report(msg:$msg, channel:null);
                        return redirect()->back()->with('error', $msg);
                    }
                } else {
                    File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                    $msg = gp247_language_render('admin.extension.error_check_config');
                    gp247_report(msg:$msg, channel:null);
                    return redirect()->back()->with('error', $msg);
                }
            } else {
                $msg = gp247_language_render('admin.extension.error_unzip');
                gp247_report(msg:$msg, channel:null);
                return redirect()->back()->with('error', $msg);
            }
        } else {
            $msg = gp247_language_render('admin.extension.error_upload');
            gp247_report(msg:$msg, channel:null);
            return redirect()->back()->with('error', $msg);
        }

        gp247_notice_add(type:$this->type, typeId: $configKey, content:'admin_notice.gp247_'.strtolower($this->type).'_import::name__'.$configKey);
        gp247_extension_update();

        if ($linkRedirect) {
            return redirect($linkRedirect)->with('success', gp247_language_render('admin.extension.import_success'));
        } else {
            return redirect()->back()->with('success', gp247_language_render('admin.extension.import_success'));
        }
    }
}
