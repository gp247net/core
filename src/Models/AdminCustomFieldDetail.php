<?php
#GP247/Core/Models/AdminCustomFieldDetail.php
namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Cache;

class AdminCustomFieldDetail extends Model
{
    use \GP247\Core\Models\ModelTrait;
    use \GP247\Core\Models\UuidTrait;
    
    public $table          = GP247_DB_PREFIX.'admin_custom_field_detail';
    protected $connection  = GP247_DB_CONNECTION;
    protected $guarded     = [];

    //Function get text description
    protected static function boot()
    {
        parent::boot();
        // before delete() method call this
        static::deleting(
            function ($obj) {
                //
            }
        );

        //Uuid
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = gp247_generate_id();
            }
        });
    }
}
