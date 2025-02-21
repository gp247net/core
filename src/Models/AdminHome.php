<?php
namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;

class AdminHome extends Model
{
    use \GP247\Core\Models\ModelTrait;
    
    public $table = GP247_DB_PREFIX.'admin_home';
    protected $connection  = GP247_DB_CONNECTION;
    protected $guarded = [];

    public static function getBlockHome()
    {
        return self::where('status', 1)
            ->orderBy('sort', 'desc')
            ->get();
    }
}
