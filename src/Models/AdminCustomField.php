<?php
#GP247/Core/Models/AdminCustomField.php
namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;
use GP247\Core\Models\AdminCustomFieldDetail;

class AdminCustomField extends Model
{
    use \GP247\Core\Models\ModelTrait;
    use \GP247\Core\Models\UuidTrait;
    
    public $table          = GP247_DB_PREFIX.'admin_custom_field';
    protected $connection  = GP247_DB_CONNECTION;
    protected $guarded     = [];

    public function details()
    {
        $data  = (new AdminCustomFieldDetail)->where('custom_field_id', $this->id)
            ->get();
        return $data;
    }


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
