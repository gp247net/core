<?php
namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * An application allowed to call the REST API (header pair apiconnection + apikey).
 *
 * The key is stored only as a SHA-256 hash: assigning `apikey` writes `apikey_hash`, so
 * a database copy never yields a usable key. The plain `apikey` column is dropped by the
 * contract migration (2026_10_27_154124); until a site has run `gp247:update` the model
 * also works with only the plain column (before 3.2.3) or with both columns.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-connection-key-hashing
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class AdminApiConnection extends Model
{
    public $table = GP247_DB_PREFIX.'api_connection';
    protected $fillable = ['description', 'apiconnection', 'apikey', 'expire', 'last_active', 'status'];
    protected $hidden = ['apikey', 'apikey_hash'];
    protected $connection = GP247_DB_CONNECTION;

    /** last_active is rewritten at most this often per connection. */
    private const ACTIVITY_INTERVAL_SECONDS = 60;

    /** @var bool|null Memo: does the table already have apikey_hash (site upgraded)? */
    private static ?bool $hasHashColumn = null;

    /** @var bool|null Memo: does the table still have the plain apikey column? */
    private static ?bool $hasPlainColumn = null;

    /**
     * Whether the upgrade migration added apikey_hash yet.
     *
     * WHY: after `composer update` but before `gp247:update` the column is missing; the
     * API and the admin screen must keep working on the plain key until then.
     *
     * @return bool
     */
    public static function hashColumnReady(): bool
    {
        if (self::$hasHashColumn === null) {
            self::$hasHashColumn = \Illuminate\Support\Facades\Schema::connection(GP247_DB_CONNECTION)
                ->hasColumn(GP247_DB_PREFIX . 'api_connection', 'apikey_hash');
        }
        return self::$hasHashColumn;
    }

    /**
     * Whether the plain apikey column is still there (the contract migration drops it).
     *
     * @return bool
     */
    public static function plainColumnExists(): bool
    {
        if (self::$hasPlainColumn === null) {
            self::$hasPlainColumn = \Illuminate\Support\Facades\Schema::connection(GP247_DB_CONNECTION)
                ->hasColumn(GP247_DB_PREFIX . 'api_connection', 'apikey');
        }
        return self::$hasPlainColumn;
    }

    /**
     * Forget the remembered table layout; called after a migration changes it so the
     * same process (gp247:update, a long-running worker, a test) sees the new columns.
     *
     * @return void
     */
    public static function forgetSchemaMemo(): void
    {
        self::$hasHashColumn = null;
        self::$hasPlainColumn = null;
    }

    /**
     * Hash of an API key as stored in apikey_hash.
     *
     * @param string $key Plain key.
     * @return string
     */
    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * A new random key (160 bits, url-safe lowercase hex).
     *
     * @return string
     */
    public static function newKey(): string
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * Setting `apikey` stores its hash only (the plain column, while it still exists,
     * is blanked; before the hash column exists it is the only place to store the key).
     *
     * @return Attribute
     */
    protected function apikey(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (!self::hashColumnReady()) {
                    return ['apikey' => (string) $value];
                }
                $columns = ['apikey_hash' => ($value === null || $value === '') ? null : self::hashKey((string) $value)];
                if (self::plainColumnExists()) {
                    $columns['apikey'] = '';
                }
                return $columns;
            },
        );
    }

    /**
     * Active, unexpired connection matching the header pair, or null.
     *
     * @param string $apiconnection Connection identifier.
     * @param string $apikey        Plain key sent by the client.
     * @return self|null
     */
    public static function check($apiconnection, $apikey)
    {
        $row = self::where('apiconnection', (string) $apiconnection)
                    ->where(function ($query) {
                        $query->whereNull('expire')
                              ->orWhere('expire', '>=', date('Y-m-d'));
                    })
                    ->where('status', 1)
                    ->first();
        if (!$row) {
            return null;
        }
        // WHY fallback to the plain column: rows not yet backfilled (site not upgraded) still match.
        $stored = (string) ($row->apikey_hash ?: ((string) $row->apikey !== '' ? self::hashKey((string) $row->apikey) : ''));
        if ($stored === '' || !hash_equals($stored, self::hashKey((string) $apikey))) {
            return null;
        }
        return $row;
    }

    /**
     * Record use of the connection, at most once per ACTIVITY_INTERVAL_SECONDS.
     *
     * @return void
     */
    public function touchActivity(): void
    {
        $last = $this->last_active ? \Carbon\Carbon::parse($this->last_active) : null;
        if ($last === null || $last->lt(now()->subSeconds(self::ACTIVITY_INTERVAL_SECONDS))) {
            $this->forceFill(['last_active' => gp247_time_now()])->saveQuietly();
        }
    }
}
