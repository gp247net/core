<?php
namespace GP247\Core\Admin\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

trait ExtensionOnlineController
{
    public function index()
    {
        $arrExtensions = [];
        $resultItems = '';
        $htmlPaging = '';
        $gp247_version = config('gp247.core');
        $filter_free = request('filter_free', 0);
        $filter_type = request('filter_type', '');
        $filter_keyword = request('filter_keyword', '');

        $page = request('page') ?? 1;
        $url = config('gp247-config.env.GP247_LIBRARY_API').'/'.strtolower($this->groupType).'/?page[size]=20&page[number]='.$page;
        $url .='&version='.$gp247_version;
        $url .='&filter_free='.$filter_free;
        $url .='&filter_type='.$filter_type;
        $url .='&filter_keyword='.$filter_keyword;
        $ch            = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $dataApi   = curl_exec($ch);
        curl_close($ch);
        $dataApi = json_decode($dataApi, true);
        if (!empty($dataApi['data'])) {
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
                    'gp247_version'  => $data['gp247_version'] ?? '',
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
            $resultItems = gp247_language_render('product.admin.result_item', ['item_from' => $dataApi['from'] ?? 0, 'item_to' => $dataApi['to']??0, 'total' =>  $dataApi['total'] ?? 0]);
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
    
    
        $title = gp247_language_render('admin.extension.management', ['extension' => $this->type]);

        switch ($this->type) {
            case 'Template':
                $urlAction = [
                    'install' => gp247_route_admin('admin_template_online.install'),
                    'local' => gp247_route_admin('admin_template.index'),
                    'urlImport' => gp247_route_admin('admin_template.import'),
                ];
                break;
            
            default:
                $urlAction = [
                    'install' => gp247_route_admin('admin_plugin_online.install'),
                    'local' => gp247_route_admin('admin_plugin.index'),
                    'urlImport' => gp247_route_admin('admin_plugin.import'),
                ];
                break;
        }

    
        return view('gp247-core::screen.extension_online')->with(
            [
                    "title"              => $title,
                    "arrExtensionsLocal" => gp247_extension_get_all_local(type: $this->type),
                    "arrExtensions"      => $arrExtensions,
                    "filter_keyword"     => $filter_keyword ?? '',
                    "filter_type"        => $filter_type ?? '',
                    "filter_free"        => $filter_free ?? '',
                    "resultItems"        => $resultItems,
                    "htmlPaging"         => $htmlPaging,
                    "dataApi"            => $dataApi,
                    "urlAction"          => $urlAction,
                ]
        );
    }

    public function install()
    {
        $key = request('key');
        $path = request('path');

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
            $data = file_get_contents($path);
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
                $requireCore = $config['requireCore'] ?? [];
                if (!gp247_extension_check_compatibility($config)) {
                    File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                    $response = ['error' => 1, 'msg' => gp247_language_render('admin.extension.not_compatible', ['version' => $requireCore, 'gp247_version' => config('gp247.core')])];
                } else {
                    $folderName = explode('/gp247.json', $checkConfig[0]);
                    $folderName = explode('/', $folderName[0]);
                    $folderName = end($folderName);
                    File::copyDirectory(storage_path('tmp/'.$pathTmp.'/'.$folderName.'/public'), public_path($appPath));
                    File::copyDirectory(storage_path('tmp/'.$pathTmp.'/'.$folderName), app_path($appPath));
                    File::deleteDirectory(storage_path('tmp/'.$pathTmp));
                    $namespace = gp247_extension_get_class_config(type:$this->type, key:$key);
                    $response = (new $namespace)->install();
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
            gp247_notice_add(type: $this->type, typeId: $key, content:'admin_notice.gp247_'.strtolower($this->type).'_install::name__'.$key);
            gp247_extension_update();
        }
        
        return response()->json($response);
    }
}
