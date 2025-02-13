<?php
namespace GP247\Core\Admin\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

trait ExtensionOnlineController
{
    public function index()
    {
        // Khởi tạo các biến cần thiết
        $arrExtensions = [];  // Mảng chứa danh sách extensions
        $resultItems = '';    // Chuỗi hiển thị kết quả tìm kiếm
        $htmlPaging = '';     // HTML phân trang
        
        // Lấy các tham số từ request
        $gp247_version = config('gp247.core');  // Version của core
        $filter_free = request('filter_free', 0);  // Lọc extension miễn phí
        $filter_type = request('filter_type', ''); // Lọc theo loại
        $filter_keyword = request('filter_keyword', ''); // Từ khóa tìm kiếm
        
        // Xây dựng URL API với các tham số
        $page = request('page') ?? 1;
        $url = config('gp247-config.env.GP247_LIBRARY_API').'/'.strtolower($this->groupType).'/?page[size]=20&page[number]='.$page;
        $url .='&version='.$gp247_version;
        $url .='&filter_free='.$filter_free;
        $url .='&filter_type='.$filter_type;
        $url .='&filter_keyword='.$filter_keyword;

        // Gọi API lấy danh sách extensions
        try {
            // Khởi tạo CURL
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \Exception('Failed to initialize CURL');
            }
            
            // Cấu hình các options cho CURL
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Trả về kết quả thay vì in ra
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);  // Timeout sau 10s
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // Bỏ qua verify SSL
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); // Bỏ qua verify host
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Cho phép redirect
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // Số lần redirect tối đa
            
            // Thực thi CURL
            $dataApi = curl_exec($ch);
            
            // Kiểm tra lỗi CURL
            if ($dataApi === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new \Exception('CURL Error: ' . $error);
            }
            
            // Get response information
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            
            // Only log URLs if there is a redirect (final URL is different from original URL)
            if ($finalUrl !== $url) {
                gp247_report(msg: 'Redirect detected:', channel: null);
                gp247_report(msg: '- Original URL: ' . $url, channel: null);
                gp247_report(msg: '- Final URL: ' . $finalUrl, channel: null);
            }
            
            curl_close($ch);
            
            // Check HTTP status code
            if ($httpCode !== 200) {
                throw new \Exception('HTTP Error: ' . $httpCode . ' for URL: ' . $finalUrl);
            }
            
            // Parse JSON response
            $dataApi = json_decode($dataApi, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON decode error: ' . json_last_error_msg());
            }

        } catch (\Exception $e) {
            // Log lỗi và set data mặc định nếu có lỗi
            gp247_report(msg: 'API Error: ' . $e->getMessage(), channel: null);
            $dataApi = ['data' => [], 'error' => $e->getMessage()];
        }

        // Xử lý dữ liệu trả về từ API
        if (!empty($dataApi['data'])) {
            // Map dữ liệu vào mảng extensions
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
            
            // Tạo HTML phân trang
            $resultItems = gp247_language_render('product.admin.result_item', [
                'item_from' => $dataApi['from'] ?? 0, 
                'item_to' => $dataApi['to']??0, 
                'total' =>  $dataApi['total'] ?? 0
            ]);
            
            // Xây dựng HTML phân trang
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
    
    
        $title = gp247_language_render('admin.extension.management', ['extension' => $this->groupType]);

        switch ($this->groupType) {
            case 'Templates':
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
                    "arrExtensionsLocal" => gp247_extension_get_all_local(type: $this->groupType),
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
                    $namespace = gp247_extension_get_class_config(type:$this->groupType, key:$key);
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
            gp247_notice_add(type: $this->groupType, typeId: $key, content:'admin_notice.gp247_'.strtolower($this->groupType).'_install::name__'.$key);
            gp247_extension_update();
        }
        
        return response()->json($response);
    }
}
