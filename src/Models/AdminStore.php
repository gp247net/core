<?php
namespace GP247\Core\Models;

use GP247\Core\Models\AdminStoreDescription;
use GP247\Core\Models\AdminConfig;
use Illuminate\Database\Eloquent\Model;


class AdminStore extends Model
{   

    use \GP247\Core\Models\UuidTrait;
    
    public $table = GP247_DB_PREFIX.'admin_store';
    protected $connection = GP247_DB_CONNECTION;
    protected $guarded = [];
    protected static $getAll = null;
    protected static $getStoreActive = null;
    protected static $getCodeActive = null;
    protected static $getDomainPartner = null;
    protected static $getDomainStore = null;
    protected static $getListAllActive = null;
    protected static $arrayStoreId = null;
    protected static $listStoreId = null;
    protected static $listStoreCode = null;
    protected static $getStoreDomainByCode = null;
    
    public function descriptions()
    {
        return $this->hasMany(AdminStoreDescription::class, 'store_id', 'id');
    }
    protected static function boot()
    {
        parent::boot();
        // before delete() method call this
        static::deleting(function ($store) {
            //Store id 1 is default
            if ($store->id == GP247_STORE_ID_ROOT) {
                return false;
            }
            //Delete store descrition
            $store->descriptions()->delete();
            AdminConfig::where('store_id', $store->id)->delete();
        });

        //Uuid
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = gp247_generate_id('STO');
            }
        });
    }


    /**
     * [getAll description]
     *
     * @return  [type]  [return description]
     */
    public static function getListAll()
    {
        if (self::$getAll === null) {
            self::$getAll = self::with('descriptions')
                ->get()
                ->keyBy('id');
        }
        return self::$getAll;
    }

    /**
     * Store titles keyed by store id, for admin store pickers. The
     * admin_store table has no name column — the title lives in the
     * per-language description row — so callers must NOT pluck('name');
     * falls back to the store code when the locale has no description.
     *
     * @return array<int|string, string>
     */
    public static function getListTitle(): array
    {
        return self::getListAll()->map(function ($store) {
            $title = $store->getTitle();

            return $title !== '' ? $title : (string) $store->code;
        })->all();
    }

    /**
     * [getAll active description]
     *
     * @return  [type]  [return description]
     */
    public static function getListAllActive()
    {
        if (self::$getListAllActive === null) {
            self::$getListAllActive = self::with('descriptions')
                ->where('active', 1)
                ->get()
                ->keyBy('id');
        }
        return self::$getListAllActive;
    }


    /**
     * Get all domain and id store is vendor unlock domain
     *
     * @return  [array]  [return description]
     */
    public static function getDomainPartner()
    {
        if (self::$getDomainPartner === null) {
            self::$getDomainPartner = self::where('partner', 1)
                ->whereNotNull('domain')
                ->where('status', 1)
                ->pluck('domain', 'id')
                ->all();
        }
        return self::$getDomainPartner;
    }
    

    /**
     * Get all domain and id store unlock domain
     *
     * @return  [array]  [return description]
     */
    public static function getDomainStore()
    {
        if (self::$getDomainStore === null) {
            self::$getDomainStore = self::whereNotNull('domain')
                ->where('status', 1)
                ->pluck('domain', 'id')
                ->all();
        }
        return self::$getDomainStore;
    }

    /**
     * Get all domain and id store active
     *
     * @return  [array]  [return description]
     */
    public static function getStoreActive()
    {
        if (self::$getStoreActive === null) {
            self::$getStoreActive = self::where('active', 1)
                ->pluck('domain', 'id')
                ->all();
        }
        return self::$getStoreActive;
    }
    

    /**
     * Get all code and id store active
     *
     * @return  [array]  [return description]
     */
    public static function getCodeActive()
    {
        if (self::$getCodeActive === null) {
            self::$getCodeActive = self::where('active', 1)
                ->pluck('code', 'id')
                ->all();
        }
        return self::$getCodeActive;
    }

    /**
     * Get array store ID
     *
     * @return array
     */
    public static function getArrayStoreId()
    {
        if (self::$arrayStoreId === null) {
            self::$arrayStoreId = self::pluck('id')->all();
        }
        return self::$arrayStoreId;
    }

    //Function get text description
    public function getText()
    {
        return $this->descriptions()->where('lang', gp247_get_locale())->first();
    }
    public function getTitle()
    {
        return $this->getText()->name ?? '';
    }
    public function getDescription()
    {
        return $this->getText()->description ?? '';
    }
    public function getKeyword()
    {
        return $this->getText()->keyword?? '';
    }
    //End  get text description

    
    //===========Get infor store======
    /**
     * Get list store ID
     */
    public static function getListStoreId()
    {
        if (self::$listStoreId === null) {
            self::$listStoreId = self::pluck('id', 'code')->all();
        }
        return self::$listStoreId;
    }

    /**
     * Get list store code
     */
    public static function getListStoreCode()
    {
        if (self::$listStoreCode === null) {
            self::$listStoreCode = self::pluck('code', 'id')->all();
        }
        return self::$listStoreCode;
    }

    /**
     * Get all domain and code store active
     *
     * @return  [array]  [return description]
     */
    public static function getStoreDomainByCode()
    {
        if (self::$getStoreDomainByCode === null) {
            self::$getStoreDomainByCode = self::whereNotNull('domain')
                ->pluck('domain', 'code')
                ->all();
        }
        return self::$getStoreDomainByCode;
    }

    /**
     * Get all template used
     *
     * @return  [type]  [return description]
     */
    public static function getAllTemplateUsed()
    {
        return self::pluck('template')->all();
    }

    public static function insertDescription(array $data)
    {
        return AdminStoreDescription::insert($data);
    }

    /**
     * Update description
     *
     * @param   array  $data  [$data description]
     *
     * @return  [type]        [return description]
     */
    public static function updateDescription(array $data)
    {
        $checkDes = AdminStoreDescription::where('store_id', $data['storeId'])
        ->where('lang', $data['lang'])
        ->first();
        if ($checkDes) {
            return AdminStoreDescription::where('store_id', $data['storeId'])
            ->where('lang', $data['lang'])
            ->update([$data['name'] => $data['value']]);
        } else {
            return AdminStoreDescription::insert(
                [
                    'store_id' => $data['storeId'],
                    'lang' => $data['lang'],
                    $data['name'] => $data['value'],
                ]
            );
        }
    }

    /**
     * Set up data default for new store
     *
     * @param   AdminStore  $store  [$store description]
     *
     * @return  [type]        [return description]
     */
    public static function setUpDataDefault(AdminStore $store) {
        $storeId = $store->id;
        //Add config default for new store
        session(['lastStoreId' => $storeId]);
        session(['lastStoreTemplate' => $store->template]);
        // WHY: seeders moved in 2.x (GP247\Core\DB\seeders -> GP247\Core\Database\Seeders,
        // GP247\Shop\DB\seeders -> GP247\Shop\Admin\Database\Seeders); the old string
        // class names kept working nowhere and only surfaced when a plugin (e.g.
        // MultiStorePro) created a sub-store. ::class references fail at parse-time
        // instead of runtime if they move again.
        \Illuminate\Support\Facades\Artisan::call('db:seed', [
            '--class' => \GP247\Core\Database\Seeders\DataStoreSeeder::class,
            '--force' => true
        ]);
        // WHY: gp247/shop is optional for core — skip its default data on a core-only site.
        if (class_exists(\GP247\Shop\Admin\Database\Seeders\DataShopDefaultSeeder::class)) {
            \Illuminate\Support\Facades\Artisan::call('db:seed', [
                '--class' => \GP247\Shop\Admin\Database\Seeders\DataShopDefaultSeeder::class,
                '--force' => true
            ]);
        }

        //Setup store with template
        $template = $store->template;
        $classTemplate = 'App\GP247\Templates\\'.$template.'\AppConfig';
        if (class_exists($classTemplate)) {
            $template = new $classTemplate;
            if (method_exists($template, 'setupStore')) {
                $template->setupStore($storeId);
            }
        }

        session()->forget('lastStoreTemplate');
        session()->forget('lastStoreId');
    }
}
