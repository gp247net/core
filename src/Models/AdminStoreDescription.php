<?php
#GP247/Core/Models/AdminStoreDescription.php
namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;

class AdminStoreDescription extends Model
{
    use \GP247\Core\Models\ModelTrait;
    
    protected $primaryKey = ['lang', 'store_id'];
    public $incrementing = false;
    protected $guarded = [];
    public $timestamps = false;
    public $table = GP247_DB_PREFIX.'admin_store_description';
    protected $connection = GP247_DB_CONNECTION;
}
