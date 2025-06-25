<?php
namespace GP247\Core\Controllers;

use Illuminate\Support\Facades\File;

trait  ExtensionController
{
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

        return view('gp247-core::screen.extension')->with(
            [
                "title"               => gp247_language_render('admin.extension.management', ['extension' => $this->groupType]),
                "groupType"           => $this->groupType,
                "configExtension"     => config('gp247-config.admin.api_'.strtolower($this->groupType)),
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
        $namespace = gp247_extension_get_namespace(type:$this->groupType, key:$key);
        $namespace = $namespace . '\AppConfig';
        $config = json_decode(file_get_contents(app_path('GP247/'.$this->groupType.'/'.$key.'/gp247.json')), true);
        $requireFaild = gp247_extension_check_compatibility($config);
        if($requireFaild) {
            return response()->json(['error' => 1, 'msg' => gp247_language_render('admin.extension.not_compatible', ['msg' => json_encode($requireFaild)])]);
        }
        if (class_exists($namespace)) {
            //Check method install exist
            if (method_exists($namespace, 'install')) {
                $response = (new $namespace)->install();
                if (is_array($response) && $response['error'] == 0) {
                    gp247_notice_add(type:$this->groupType, typeId: $key, content:'admin_notice.gp247_'.strtolower($this->groupType).'_install::name__'.$key);
                    gp247_extension_after_update();
                }
            } else {
                return response()->json(['error' => 1, 'msg' => 'Method install not found']);
            }
        } else {
            return response()->json(['error' => 1, 'msg' => 'Class not found']);
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

        $namespace = gp247_extension_get_namespace(type:$this->groupType, key:$key);
        $namespace = $namespace . '\AppConfig';
        $extensionsInstalled = gp247_extension_get_installed(type:$this->groupType, active: false);
        // Check class exist and extension installed
        if (class_exists($namespace) && array_key_exists($key, $extensionsInstalled->toArray())) {
            //Check method uninstall exist
            if (method_exists($namespace, 'uninstall')) {
                $response = (new $namespace)->uninstall();
                if (is_array($response) && $response['error'] == 0) {
                gp247_notice_add(type:$this->groupType, typeId: $key, content:'admin_notice.gp247_'.strtolower($this->groupType).'_uninstall::name__'.$key);
                    gp247_extension_after_update();
                }
            } else {
                return response()->json(['error' => 1, 'msg' => 'Method uninstall not found']);
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
        $namespace = gp247_extension_get_namespace(type:$this->groupType, key:$key);
        $namespace = $namespace . '\AppConfig';
        //Check method enable exist
        if (method_exists($namespace, 'enable')) {
            $response = (new $namespace)->enable();
            if (is_array($response) && $response['error'] == 0) {
                gp247_notice_add(type:$this->groupType, typeId: $key, content:'admin_notice.gp247_'.strtolower($this->groupType).'_enable::name__'.$key);
                gp247_extension_after_update();
            }
        } else {
            return response()->json(['error' => 1, 'msg' => 'Method enable not found']);
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

        $namespace = gp247_extension_get_namespace(type:$this->groupType, key:$key);
        $namespace = $namespace . '\AppConfig';
        //Check method disable exist
        if (method_exists($namespace, 'disable')) {
            $response = (new $namespace)->disable();
        if (is_array($response) && $response['error'] == 0) {
            gp247_notice_add(type: $this->groupType, typeId: $key, content:'admin_notice.gp247_'.strtolower($this->groupType).'_disable::name__'.$key);
                gp247_extension_after_update();
            }
        } else {
            return response()->json(['error' => 1, 'msg' => 'Method disable not found']);
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
        $data =  [
            'title' => gp247_language_render('admin.extension.import').': '.$this->groupType,
            'urlAction' => $urlAction
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

        if (!is_writable(storage_path('tmp'))) {
            $msg = 'No write permission '.storage_path('tmp');
            gp247_report(msg:$msg, channel:null);
            return response()->json(['error' => 1, 'msg' => $msg]);
        }

        $dataFile = gp247_file_upload($data['file'], $disk = 'tmp', $pathFolder = $pathTmp);
        
        if ($dataFile['error'] == 0) {
            $pathFile = $dataFile['data']['pathFile'] ?? '';
            $unzip = gp247_unzip(storage_path('tmp/'.$pathFile), storage_path('tmp/'.$pathTmp));
            if ($unzip) {
                $checkConfig = glob(storage_path('tmp/'.$pathTmp) . '/*/gp247.json');
                if ($checkConfig) {
                    $folderName = explode('/gp247.json', $checkConfig[0]);
                    $folderName = explode('/', $folderName[0]);
                    $folderName = end($folderName);
                    
                    //Check compatibility 
                    $config = json_decode(file_get_contents($checkConfig[0]), true);
                    $requireFaild = gp247_extension_check_compatibility($config);
                    if ($requireFaild) {
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        return redirect()->back()->with('error', gp247_language_render('admin.extension.not_compatible', ['msg' => json_encode($requireFaild)]));
                    }

                    $configGroup = $config['configGroup'] ?? '';
                    $configKey = $config['configKey'] ?? '';

                    //Process if extention config incorect
                    if (!$configGroup || !$configKey) {
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        return redirect()->back()->with('error', gp247_language_render('admin.extension.error_config_format'));
                    }
                    //Check extension exist
                    $arrPluginLocal = gp247_extension_get_all_local(type: $this->groupType);
                    if (array_key_exists($configKey, $arrPluginLocal)) {
                        $msg = gp247_language_render('admin.extension.error_exist');
                        gp247_report(msg:$msg, channel:null);
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        return redirect()->back()->with('error', $msg);
                    }

                    $appPath = 'GP247/'.$configGroup.'/'.$configKey;

                    if (!is_writable($checkPubPath = public_path('GP247/'.$configGroup))) {
                        $msg = 'Import extension error: No write permission '.$checkPubPath;
                        gp247_report(msg:$msg, channel:null);
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        return redirect()->back()->with('error', $msg);
                    }
            
                    if (!is_writable($checkAppPath = app_path('GP247/'.$configGroup))) {
                        $msg = 'Import extension error: No write permission '.$checkAppPath;
                        gp247_report(msg:$msg, channel:null);
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        return redirect()->back()->with('error', $msg);
                    }

                    try {
                        File::copyDirectory(storage_path('tmp/'.$pathTmp.'/'.$folderName.'/public'), public_path($appPath));
                        File::copyDirectory(storage_path('tmp/'.$pathTmp.'/'.$folderName), app_path($appPath));
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        $namespace = gp247_extension_get_namespace(type:$this->groupType, key:$configKey);
                        $namespace = $namespace . '\AppConfig';
                        //Check class exist
                        if (class_exists($namespace)) {
                            //Check method install exist
                            if (method_exists($namespace, 'install')) {
                                $response = (new $namespace)->install();
                                if (!is_array($response) || $response['error'] == 1) {
                                    $msg = $response['msg'];
                                    gp247_report(msg:$msg, channel:null);
                                    return redirect()->back()->with('error', $msg);
                                }
                            } else {
                                return redirect()->back()->with('error', 'Method install not found');
                            }
                        } else {
                            return redirect()->back()->with('error', 'Class not found');
                        }
                        $linkRedirect = route('admin_plugin.index');
                    } catch (\Throwable $e) {
                        File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                        $msg = 'Import extension error: '.$e->getMessage();
                        gp247_report(msg:$msg, channel:null);
                        return redirect()->back()->with('error', $msg);
                    }
                } else {
                    File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                    $msg = 'Import extension error: '.gp247_language_render('admin.extension.error_check_config');
                    gp247_report(msg:$msg, channel:null);
                    return redirect()->back()->with('error', $msg);
                }
            } else {
                $msg = 'Import extension error: '.gp247_language_render('admin.extension.error_unzip');
                gp247_report(msg:$msg, channel:null);
                return redirect()->back()->with('error', $msg);
            }
        } else {
            $msg = 'Import extension error: '.$dataFile['msg'];
            return redirect()->back()->with('error', $msg);
        }

        gp247_notice_add(type:$this->groupType, typeId: $configKey, content:'admin_notice.gp247_'.strtolower($this->groupType).'_import::name__'.$configKey);
        gp247_extension_after_update();

        if ($linkRedirect) {
            return redirect($linkRedirect)->with('success', gp247_language_render('admin.extension.import_success'));
        } else {
            return redirect()->back()->with('success', gp247_language_render('admin.extension.import_success'));
        }
    }
}
