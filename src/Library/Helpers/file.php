<?php
/**
 * File function process image
 */
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

/**
 * Function upload file
 */
if (!function_exists('gp247_file_upload') && !in_array('gp247_file_upload', config('gp247_functions_except', []))) {
    function gp247_file_upload($fileContent, $disk = 'public', $path = null, $name = null)
    {
        $dataReturn = [
            'error' => 0,
            'msg' => '',
            'data' => [],
        ];
        try {
            $fileName = false;
            if ($name) {
                $fileName = $name . '.' . $fileContent->getClientOriginalExtension();
            } else {
                $fileName = $fileContent->getClientOriginalName();
            }

            //Save as file
            if ($fileName) {
                $pathFile = Storage::disk($disk)->putFileAs(($path ?? ''), $fileContent, $fileName);
            }
            //Save file id unique
            else {
                $pathFile = Storage::disk($disk)->putFile(($path ?? ''), $fileContent);
            }
        } catch (\Throwable $e) {
            $dataReturn['error'] = 1;
            $dataReturn['msg'] = $e->getMessage();
            gp247_report($e->getMessage());
            return $dataReturn;
        }
        if ($disk == 'public') {
            $url =  'storage/' . $pathFile;
        } else {
            $url =  Storage::disk($disk)->url($pathFile);
        }

        $dataReturn['data'] = [
            'fileName' => $fileName,
            'pathFile' => $pathFile,
            'url' => $url,
        ];
        return $dataReturn;
    }
}
/**
 * Remove file
 *
 * @param   [string]  $disk
 * @param   [string]  $path
 * @param   [string]  $prefix  will remove
 *
 */
if (!function_exists('gp247_remove_file') && !in_array('gp247_remove_file', config('gp247_functions_except', []))) {
    function gp247_remove_file($pathFile, $disk = null)
    {
        try {
            if ($disk) {
                return Storage::disk($disk)->delete($pathFile);
            } else {
                return Storage::delete($pathFile);
            }
        } catch (\Throwable $e) {
            gp247_report($e->getMessage());
            return false;
        }
    }
}

/**
 * Function rener image
 */
if (!function_exists('gp247_image_render') && !in_array('gp247_image_render', config('gp247_functions_except', []))) {
    function gp247_image_render($path, $width = null, $height = null, $alt = null, $title = null, $default = null, $options = '')
    {
        $image = gp247_image_get_path($path, $default);
        $style = '';
        $style .= ($width) ? ' width:' . $width . ';' : '';
        $style .= ($height) ? ' height:' . $height . ';' : '';
        return '<img  alt="' . $alt . '" title="' . $title . '" ' . (($options) ?? '') . ' src="' . gp247_file($image) . '"   ' . ($style ? 'style="' . $style . '"' : '') . '   >';
    }
}
/*
Return path image
 */
if (!function_exists('gp247_image_get_path') && !in_array('gp247_image_get_path', config('gp247_functions_except', []))) {
    function gp247_image_get_path($path, $default = null)
    {
        $image = $default ?? 'GP247/Core/images/no-image.jpg';
        if ($path) {
            if (file_exists(public_path($path)) || filter_var(str_replace(' ', '%20', $path), FILTER_VALIDATE_URL)) {
                $image = $path;
            } else {
                $image = $image;
            }
        }
        return $image;
    }
}
/*
Function get path thumb of image if saved in storage
 */
if (!function_exists('gp247_image_get_path_thumb') && !in_array('gp247_image_get_path_thumb', config('gp247_functions_except', []))) {
    function gp247_image_get_path_thumb($pathFile)
    {
        if (!$pathFile) {
            return gp247_image_get_path(null);
        }
        
        $arrPath = explode('/', $pathFile);
        $fileName = array_pop($arrPath);
        $pathThumb = implode('/', $arrPath) . '/thumb/' . $fileName;
        
        if (file_exists(public_path($pathThumb))) {
            return $pathThumb;
        }
        
        return gp247_image_get_path($pathFile);
    }
}



if (!function_exists('gp247_zip') && !in_array('gp247_zip', config('gp247_functions_except', []))) {
    /*
    Zip file or folder
     */
    function gp247_zip($pathToSource = "", $pathSaveTo = "")
    {
        if (!is_string($pathToSource) || !is_string($pathSaveTo)) {
            return false;
        }
        if (extension_loaded('zip')) {
            if (file_exists($pathToSource)) {
                try {
                    $zip = new \ZipArchive();
                    if ($zip->open($pathSaveTo, \ZIPARCHIVE::CREATE)) {
                        $pathToSource = str_replace('\\', '/', realpath($pathToSource));
                        if (is_dir($pathToSource)) {
                            $iterator = new \RecursiveDirectoryIterator($pathToSource);
                            // skip dot files while iterating
                            $iterator->setFlags(\RecursiveDirectoryIterator::SKIP_DOTS);
                            $files = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::SELF_FIRST);
                            foreach ($files as $file) {
                                $file = str_replace('\\', '/', realpath($file));
                                if (is_dir($file)) {
                                    $zip->addEmptyDir(str_replace($pathToSource . '/', '', $file . '/'));
                                } elseif (is_file($file)) {
                                    $zip->addFromString(str_replace($pathToSource . '/', '', $file), file_get_contents($file));
                                }
                            }
                        } elseif (is_file($pathToSource)) {
                            $zip->addFromString(basename($pathToSource), file_get_contents($pathToSource));
                        }
                    }
                    return $zip->close();
                } catch (\Throwable $e) {
                    gp247_report($e->getMessage());
                    return false;
                }
            }
        }
        return false;
    }
}


if (!function_exists('gp247_unzip') && !in_array('gp247_unzip', config('gp247_functions_except', []))) {
    /**
     * Unzip file to folder
     *
     * @return  [type]  [return description]
     */
    function gp247_unzip($pathToSource = "", $pathSaveTo = "")
    {
        if (!is_string($pathToSource) || !is_string($pathSaveTo)) {
            return false;
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open(str_replace("//", "/", $pathToSource)) === true) {
                $zip->extractTo($pathSaveTo);
                return $zip->close();
            }
        } catch (\Throwable $e) {
            gp247_report($e->getMessage());
            return false;
        }
    }
}


/**
 * Process path file
 */
if (!function_exists('gp247_file') && !in_array('gp247_file', config('gp247_functions_except', []))) {
    function gp247_file($pathFile = "", bool $security = false):string
    {
        if (!is_string($pathFile)) {
            return '';
        }
        
        // Check if the current request is using HTTPS
        $isSecure = request()->isSecure();
        
        // If the request is secure (HTTPS), force the asset URL to use HTTPS
        if ($isSecure) {
            return asset($pathFile, true);
        }
        
        return asset($pathFile, $security);
    }
}


if (!function_exists('gp247_convertPHPSizeToBytes') && !in_array('gp247_convertPHPSizeToBytes', config('gp247_functions_except', []))) {
    /**
    * This function transforms the php.ini notation for numbers (like '2M') to an integer (2*1024*1024 in this case)
    * 
    * @param string $sSize
    * @return integer The value in bytes
    */
    function gp247_convertPHPSizeToBytes(string $sSize):int
    {
        $sSuffix = strtoupper(substr($sSize, -1));
        if (!in_array($sSuffix,array('P','T','G','M','K'))){
            return (int)$sSize;  
        } 
        $iValue = substr($sSize, 0, -1);
        switch ($sSuffix) {
            case 'P':
                $iValue *= 1024;
                // Fallthrough intended
            case 'T':
                $iValue *= 1024;
                // Fallthrough intended
            case 'G':
                $iValue *= 1024;
                // Fallthrough intended
            case 'M':
                $iValue *= 1024;
                // Fallthrough intended
            case 'K':
                $iValue *= 1024;
                break;
        }
        return (int)$iValue;
    }
}

if (!function_exists('gp247_getMaximumFileUploadSize') && !in_array('gp247_getMaximumFileUploadSize', config('gp247_functions_except', []))) {
    /**
    * This function returns the maximum files size that can be uploaded 
    * in PHP
    * @returns  File size in bytes
    **/
    function gp247_getMaximumFileUploadSize($unit = null)
    {
        $valueUnit = 1;
        switch ($unit) {
            case 'P':
                $valueUnit = 1024 * 1024 * 1024 * 1024 * 1024;
                break;
            case 'T':
                $valueUnit = 1024 * 1024 * 1024 * 1024;
                break;
            case 'G':
                $valueUnit = 1024 * 1024 * 1024;
                break;
            case 'M':
                $valueUnit = 1024 * 1024;
                break;
            case 'K':
                $valueUnit = 1024;
                break;
            default:
                $valueUnit = 1;
                break;
        }
        return min(gp247_convertPHPSizeToBytes(ini_get('post_max_size')), gp247_convertPHPSizeToBytes(ini_get('upload_max_filesize')))/ $valueUnit;
    }
}


if (!function_exists('gp247_process_private_folder') && !in_array('gp247_process_private_folder', config('gp247_functions_except', []))) {

    function gp247_process_private_folder()
    {
        // For link uploads?type=
        //Process private folder for laravel file manager if packages multi app exist
        if (session('partner_id') && \Illuminate\Support\Str::startsWith(request('type'),['pmo_','partner_'])) {
            return session('partner_id');
        }
        if (session('adminStoreId') && \Illuminate\Support\Str::startsWith(request('type'),['vendor_','store_','shop_','partner_'])) {
            return session('adminStoreId');
        }
        return ;
    }
}
