<?php
namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;

class AdminApiConnection extends Model
{
    public $table = GP247_DB_PREFIX.'api_connection';
    protected $guarded = [];
    protected $connection = GP247_DB_CONNECTION;

    public static function check($apiconnection, $apikey)
    {
        return self::where('apiconnection', $apiconnection)
                    ->where('apikey', $apikey)
                    ->where(function ($query) {
                        $query->whereNull('expire')
                              ->orWhere('expire', '>=', date('Y-m-d'));
                    })
                    ->where('status', 1)
                    ->first();
    }
}
