<?php
namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;
use GP247\Core\Models\AdminLanguage;

class Languages extends Model
{
    use \GP247\Core\Models\ModelTrait;
    
    public $table = GP247_DB_PREFIX.'languages';
    protected $guarded = [];
    private static $getList = [];
    protected $connection = GP247_DB_CONNECTION;


    public static function getListAll($location)
    {
        if (!isset(self::$getList[$location])) {
            self::$getList[$location] = self::where('location', $location)->pluck('text', 'code');
        }
        return self::$getList[$location];
    }

    /**
     * Get all positions
     *
     * @return void
     */
    public static function getPosition()
    {
        return self::groupBy('position')->pluck('position')->all();
    }

    /**
     * Get all
     *
     * @param [type] $lang
     * @param [type] $position
     * @return void
     */
    public static function getLanguagesPosition($lang, $position, $keyword = null)
    {
        if (!empty($lang)) {
            $languages = AdminLanguage::getCodeAll();
            if (!in_array($lang, array_keys($languages))) {
                return  [];
            }
            $data =  self::where('location', $lang);
            if (!empty($position)) {
                $data = $data->where('position', $position);
            }
            if (!empty($keyword)) {
                $data = $data->where(function($query) use($keyword) {
                    $query->where('code', 'like', '%'.$keyword.'%')
                          ->orWhere('text', 'like', '%'.$keyword.'%');
                });
            }
            $data = $data->get()
            ->keyBy('code')
            ->toArray();
            return $data;
        } else {
            return [];
        }
    }
}
