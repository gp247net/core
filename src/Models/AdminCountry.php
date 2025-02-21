<?php
namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Cache;

class AdminCountry extends Model
{
    use \GP247\Core\Models\ModelTrait;
    
    public $table = GP247_DB_PREFIX.'admin_country';
    protected $connection = GP247_DB_CONNECTION;
    public $timestamps               = false;
    private static $getListCountries = null;
    private static $getCodeAll = null;

    public static function getListAll()
    {
        if (self::$getListCountries === null) {
            self::$getListCountries = self::get()->keyBy('code');
        }
        return self::$getListCountries;
    }

    public static function getCodeAll()
    {
        if (gp247_config_global('cache_status') && gp247_config_global('cache_country')) {
            if (!Cache::has('cache_country')) {
                if (self::$getCodeAll === null) {
                    self::$getCodeAll = self::pluck('name', 'code')->all();
                }
                gp247_cache_set('cache_country', self::$getCodeAll);
            }
            return Cache::get('cache_country');
        } else {
            if (self::$getCodeAll === null) {
                self::$getCodeAll = self::pluck('name', 'code')->all();
            }
            return self::$getCodeAll;
        }
    }
}
