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
    // WHY: explicit allow-list instead of $guarded=[]. The `id` is a UUID assigned by
    // UuidTrait on the creating event, never mass-assigned, so it is intentionally omitted
    // (RISK-TECH-custom-field-typing — no unexpected column can be set from request input).
    protected $fillable    = ['type', 'code', 'name', 'required', 'status', 'option', 'default'];

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
                // WHY: no DB-level FK/cascade exists and definitions are hard-deleted per model
                // via DataTableComponent::delete/bulkDelete (which fires this event), so cascade
                // the detail rows here to avoid orphaned custom-field data forever
                // (RISK-TECH-custom-field-orphan-data).
                AdminCustomFieldDetail::where('custom_field_id', $obj->id)->delete();
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
