<?php

namespace GP247\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class MakePlugin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:make-plugin {--name=} {--download=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make format plugin "php artisan gp247:make-plugin --name=YourPluginName --download=0"';

    protected $tmpFolder = 'tmp';
    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $name = $this->option('name') ?? '';
        $download = $this->option('download') ?? 0;
        if (empty($name)) {
            echo json_encode([
                'error' => '1',
                'msg' => 'Command error'
            ]);
            exit;
        }
        $this->extension($name, $download);
    }

    //Create format extension
    protected function extension($name = '', $download = 0)
    {
        $error = 0;
        $msg = 'Success';

        $extensionKey = gp247_word_format_class($name);
        $extensionUrlKey = gp247_word_format_url($name);
        $extensionUrlKey = str_replace('-', '_', $extensionUrlKey);

        $source = "Format/plugin";
        $sourcePublic = "Format/plugin/public";
        $destination = 'GP247/Plugins/'.$extensionKey;

        $sID = md5(time());
        $tmp = $this->tmpFolder."/".$sID.'/'.$extensionKey;
        $tmpPublic = $this->tmpFolder."/".$sID.'/'.$extensionKey.'/public';
        try {
            File::copyDirectory(base_path('vendor/gp247/core/src/'.$source), storage_path($tmp));
            File::copyDirectory(base_path('vendor/gp247/core/src/'.$sourcePublic), storage_path($tmpPublic));

            if (file_exists(storage_path($tmp.'/Admin/AdminController.php'))) {
                $adminController = file_get_contents(storage_path($tmp.'/Admin/AdminController.php'));
                $adminController      = str_replace('Extension_Key', $extensionKey, $adminController);
                $adminController      = str_replace('ExtensionUrlKey', $extensionUrlKey, $adminController);
                file_put_contents(storage_path($tmp.'/Admin/AdminController.php'), $adminController);
            }

            if (file_exists(storage_path($tmp.'/Controllers/FrontController.php'))) {
                // Process front controller
                // Check gp247/front is installed
                if (class_exists('GP247\Front\Controllers\RootFrontController')) {
                    $frontController = file_get_contents(storage_path($tmp.'/Controllers/FrontController.php'));
                    $frontController      = str_replace('Extension_Key', $extensionKey, $frontController);
                    $frontController      = str_replace('ExtensionUrlKey', $extensionUrlKey, $frontController);
                    file_put_contents(storage_path($tmp.'/Controllers/FrontController.php'), $frontController);
                } else {
                    File::delete(storage_path($tmp.'/Controllers/FrontController.php'));
                }
            }

            $model = file_get_contents(storage_path($tmp.'/Models/ExtensionModel.php'));
            $model      = str_replace('Extension_Key', $extensionKey, $model);
            $model      = str_replace('ExtensionUrlKey', $extensionUrlKey, $model);
            file_put_contents(storage_path($tmp.'/Models/ExtensionModel.php'), $model);


            $appConfigJson = file_get_contents(storage_path($tmp.'/gp247.json'));
            $appConfigJson      = str_replace('Extension_Key', $extensionKey, $appConfigJson);
            $appConfigJson          = str_replace('ExtensionUrlKey', $extensionUrlKey, $appConfigJson);
            file_put_contents(storage_path($tmp.'/gp247.json'), $appConfigJson);


            $appConfig = file_get_contents(storage_path($tmp.'/AppConfig.php'));
            $appConfig      = str_replace('Extension_Key', $extensionKey, $appConfig);
            file_put_contents(storage_path($tmp.'/AppConfig.php'), $appConfig);

            $langen = file_get_contents(storage_path($tmp.'/Lang/en/lang.php'));
            $langen      = str_replace('Extension_Key', $extensionKey, $langen);
            file_put_contents(storage_path($tmp.'/Lang/en/lang.php'), $langen);

            $langvi = file_get_contents(storage_path($tmp.'/Lang/vi/lang.php'));
            $langvi      = str_replace('Extension_Key', $extensionKey, $langvi);
            file_put_contents(storage_path($tmp.'/Lang/vi/lang.php'), $langvi);

            $provider = file_get_contents(storage_path($tmp.'/Provider.php'));
            $provider      = str_replace('Extension_Key', $extensionKey, $provider);
            $provider          = str_replace('ExtensionUrlKey', $extensionUrlKey, $provider);
            file_put_contents(storage_path($tmp.'/Provider.php'), $provider);

            if (class_exists('GP247\Front\Controllers\RootFrontController')) {
                $stubContent = file_get_contents(storage_path($tmp.'/route_front.stub'));
                $stubContent      = str_replace('Extension_Key', $extensionKey, $stubContent);
                $stubContent          = str_replace('ExtensionUrlKey', $extensionUrlKey, $stubContent);
            } else {
                $stubContent = '';
            }
            $route = file_get_contents(storage_path($tmp.'/Route.php'));
            $route      = str_replace('Extension_Key', $extensionKey, $route);
            $route          = str_replace('ExtensionUrlKey', $extensionUrlKey, $route);
            $route          = str_replace('//$stub_front', $stubContent, $route);
            file_put_contents(storage_path($tmp.'/Route.php'), $route);
            File::delete(storage_path($tmp.'/route_front.stub'));

        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $error = 1;
        }

        try {
            if ($download) {
                $path = storage_path($this->tmpFolder.'/'.$sID.'.zip');
                gp247_zip(storage_path($this->tmpFolder."/".$sID), $path);
            } else {
                File::copyDirectory(storage_path($tmp), app_path($destination));
                File::copyDirectory(storage_path($tmpPublic), public_path($destination));
            }
            File::deleteDirectory(storage_path($this->tmpFolder.'/'.$sID));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $error = 1;
        }

        echo json_encode([
            'error' => $error,
            'path' => $path ?? '',
            'msg' => $msg
        ]);
    }
}
