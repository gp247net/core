<?php
namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;

class AdminConfig extends Model
{
    public $table = GP247_DB_PREFIX.'admin_config';
    protected $connection = GP247_DB_CONNECTION;
    protected $guarded = [];

    protected static $getAllGlobal = null;

    /** @var array<int|string, array<string, mixed>> Per-storeId memoized result of getAllConfigOfStore(). */
    protected static $getAllConfigOfStore = [];

    /**
     * Model hooks: encrypt a secret value at rest on every Eloquent write.
     *
     * WHY here (not a cast): admin_config is written through both Eloquent and ~59
     * query-builder ->update() call sites, and read through pluck() (which bypasses
     * accessors). This saving() hook is the single write-choke for the ELOQUENT paths
     * (save/updateOrCreate — e.g. LoginSocial, setConfigValue, ConfigForm sub-store);
     * the read-choke lives in getAllGlobal/getAllConfigOfStore + getValueAttribute
     * (prefix-based). The query-builder secret writes encrypt explicitly at their site.
     *
     * @return void
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    protected static function booted()
    {
        static::saving(function (self $model) {
            // Decide to encrypt on the flag; skip if already enveloped (no double-encrypt).
            if ((int) ($model->attributes['security'] ?? 0) === 1) {
                $raw = $model->attributes['value'] ?? '';
                if (!gp247_secret_is_encrypted($raw)) {
                    $model->attributes['value'] = gp247_secret_encrypt((string) $raw);
                }
            }
        });
    }

    /**
     * Accessor: decrypt a secret value transparently on model-object read. Decides on
     * the envelope prefix, so a plaintext/non-secret value passes through unchanged.
     *
     * @param mixed $value Raw stored value.
     * @return string Plaintext.
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    public function getValueAttribute($value): string
    {
        return function_exists('gp247_secret_decrypt') ? gp247_secret_decrypt($value) : (string) $value;
    }

    /**
     * Write-choke for a single config row (upsert), so the saving() hook applies the
     * at-rest encryption. Prefer this over a query-builder ->update() when writing a
     * value that may be a secret.
     *
     * @param string     $group    Config group.
     * @param string     $key      Config key.
     * @param int|string $storeId  Store scope.
     * @param string     $value    Plaintext value.
     * @param int|null   $security 1 to mark (and encrypt) as secret; null keeps the row's current flag.
     * @return void
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    public static function setConfigValue(string $group, string $key, $storeId, string $value, ?int $security = null): void
    {
        $row = self::firstOrNew(['group' => $group, 'key' => $key, 'store_id' => $storeId]);
        if (!$row->exists && (string) ($row->code ?? '') === '') {
            // WHY: `code` is NOT NULL with no default — seed a sensible one for a new row
            // (existing rows keep their code untouched).
            $row->code = $group !== '' ? $group : $key;
        }
        if ($security !== null) {
            $row->security = $security;
        }
        // Assign the RAW attribute (bypass the decrypt accessor); saving() encrypts if secret.
        $row->setAttribute('value', $value);
        $row->save();
    }

    /**
     * get Plugin installed
     * @param  boolean $onlyActive
     * @return [type]              [description]
     */
    public static function getPluginCode($onlyActive = true)
    {
        // WHY: the install/enable record lives at store GLOBAL; per-store enable rows
        // (same group/key, store_id=<store>) must NOT shadow it in this list, or a
        // store toggle would corrupt the system-wide plugin roster
        // (ADR plugin-manager_per-store-plugin-config).
        $query =  self::where('group', 'Plugins')->where('store_id', GP247_STORE_ID_GLOBAL);
        if ($onlyActive) {
            $query = $query->where('value', 1);
        }
        $data = $query->orderBy('sort', 'desc')
            ->get()->keyBy('key');
        return $data;
    }

    /**
     * get Templates installed
     * @param  boolean $onlyActive
     * @return [type]              [description]
     */
    public static function getTemplateCode($onlyActive = true)
    {
        // WHY: GLOBAL-only, same reason as getPluginCode (per-store rows must not shadow).
        $query =  self::where('group', 'Templates')->where('store_id', GP247_STORE_ID_GLOBAL);
        if ($onlyActive) {
            $query = $query->where('value', 1);
        }
        $data = $query->orderBy('sort', 'desc')
            ->get()->keyBy('key');
        return $data;
    }

    /**
     * get Extension Code
     * @param  boolean $onlyActive
     * @return [type]              [description]
     */
    public static function getExtensionCode($onlyActive = true)
    {
        // WHY: GLOBAL-only, same reason as getPluginCode (per-store rows must not shadow).
        $query =  self::whereIn('group', ['Plugins', 'Templates'])->where('store_id', GP247_STORE_ID_GLOBAL);
        if ($onlyActive) {
            $query = $query->where('value', 1);
        }
        $data = $query->orderBy('sort', 'desc')
            ->get()->keyBy('key');
        return $data;
    }

    /**
     * get Plugin Captcha installed
     * @param  boolean $onlyActive
     * @return [type]              [description]
     */
    public static function getPluginCaptchaCode($onlyActive = true)
    {
        // WHY: GLOBAL-only, same reason as getPluginCode (per-store rows must not shadow).
        $query =  self::where('group', 'Plugins')
        ->where('store_id', GP247_STORE_ID_GLOBAL)
        ->where('code', 'like', '%Captcha')
        ->where('key', 'like', '%Captcha');
        if ($onlyActive) {
            $query = $query->where('value', 1);
        }
        $data = $query->orderBy('sort', 'desc')
            ->get()->keyBy('key');
        return $data;
    }


    /**
     * get Group
     * @param  [string]  $group
     * @param  [string]  $suffix
     * @return [type]              [description]
     */
    public static function getGroup($group = null, $suffix = null):array
    {
        if ($group === null) {
            return [];
        }
        $return =  self::where('group', $group);
        if ($suffix) {
            $return = $return->orWhere('group', $group.'__'.$suffix);
        }
        $return = $return->orderBy('sort', 'desc')->pluck('value')->all();
        if ($return) {
            return $return;
        } else {
            return [];
        }
    }


    /**
     * [getAllGlobal description]
     *
     * @return  [type]  [return description]
     */
    public static function getAllGlobal():array
    {
        if (self::$getAllGlobal === null) {
            // WHY map(): pluck() bypasses the value accessor, so decrypt secrets here
            // (prefix-based) — the memoized map holds plaintext, never ciphertext.
            self::$getAllGlobal = self::where('store_id', GP247_STORE_ID_GLOBAL)
                ->pluck('value', 'key')
                ->map(fn ($v) => function_exists('gp247_secret_decrypt') ? gp247_secret_decrypt($v) : $v)
                ->all();
        }
        return self::$getAllGlobal;
    }

    /**
     * [getAllConfigOfStore description]
     *
     * @param   [type]  $storeId  [$storeId description]
     *
     * @return  [type]            [return description]
     */
    public static function getAllConfigOfStore($storeId):array
    {
        if (!array_key_exists($storeId, self::$getAllConfigOfStore)) {
            // WHY map(): decrypt secrets after pluck() (accessor bypassed) — prefix-based.
            self::$getAllConfigOfStore[$storeId] = self::where('store_id', $storeId)
                ->pluck('value', 'key')
                ->map(fn ($v) => function_exists('gp247_secret_decrypt') ? gp247_secret_decrypt($v) : $v)
                ->all();
        }
        return self::$getAllConfigOfStore[$storeId];
    }

    /**
     * [getListConfigByCode description]
     *
     * @param   [array]$code     [$code description]
     *
     * @return  [type]         [return description]
     */
    public static function getListConfigByCode(array $dataQuery)
    {
        if (empty($dataQuery['code'])) {
            return null;
        }
        if (is_array($dataQuery['code'])) {
            $data = self::whereIn('code', $dataQuery['code']);
        } else {
            $data = self::where('code', $dataQuery['code']);
        }
        $storeId = $dataQuery['storeId'] ?? "";
        $sort    = $dataQuery['sort'] ?? 'desc';
        $groupBy = $dataQuery['groupBy'] ?? null;
        $keyBy   = $dataQuery['keyBy'] ?? null;
        $data = $data->where('store_id', $storeId)
            ->orderBy('sort', $sort)
            ->get();
        if ($groupBy) {
            $data = $data->groupBy($groupBy);
        } elseif ($keyBy) {
            $data = $data->keyBy($keyBy);
        }
        return $data;
    }
}
