<?php

namespace GP247\Core\Tests\Feature\Compat;

use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Facades\DB;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Store-id string-identity guarantees (US-CMP-store-id-string-binding, ADR
 * compat-foundation_store-id-string-identity, RISK-TECH-store-id-numeric-coercion).
 *
 * Every store_id column across core/front/shop is uuid/char(36) and may hold a
 * non-numeric id (e.g. 'STO-cCmKW'). Binding a store filter as a PHP int makes
 * Laravel send PDO::PARAM_INT, which forces MySQL to coerce the whole char column
 * to DOUBLE — throwing SQLSTATE[22007] 1292 "Truncated incorrect DOUBLE value" the
 * moment a 'STO-...' row is in scope (the exact crash when saving ADMIN_NAME on a
 * multi-store site). Making the store-id constants strings keeps every derived
 * binding a string comparison.
 *
 * The DB case reproduces MySQL strict-mode behaviour, so it needs a real MySQL/
 * MariaDB connection (SQLite is dynamically typed and never truncates) — it runs
 * in tier-2/tier-3 (CI or `DB_CONNECTION=mysql DB_DATABASE=s-cart GP247_SEO_LANG=0`),
 * not the driver-less local inner loop. The constant-type case is pure PHP and
 * always runs.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-store-id-string-binding
 * @aidlc-adr compat-foundation_store-id-string-identity
 */
class StoreIdStringBindingTest extends TestCase
{
    use DatabaseTransactions;

    /** Config key used only by this test (avoids touching seeded rows / unique(key,store_id)). */
    private const TEST_KEY = 'ZZ_STOREID_BIND_TEST';

    /** A non-numeric store id, the shape that triggers the DOUBLE-coercion crash. */
    private const STRING_STORE_ID = 'STO-TESTxx';

    /**
     * The two store-identity constants must be strings so `->where('store_id', …)`
     * binds PDO::PARAM_STR and never coerces the char column to a number.
     *
     * @return void
     */
    public function test_store_id_constants_are_strings(): void
    {
        $this->assertSame('1', GP247_STORE_ID_ROOT, 'ROOT store id must be the string "1"');
        $this->assertSame('0', GP247_STORE_ID_GLOBAL, 'GLOBAL store id must be the string "0"');
    }

    /**
     * Updating a config row at ROOT scope must not crash — and must touch only the
     * ROOT row — when a row with a non-numeric store_id coexists in the same table.
     * Before the fix (int ROOT constant) this throws QueryException 1292.
     *
     * @return void
     */
    public function test_root_scope_config_update_survives_a_string_store_id_row(): void
    {
        $table = GP247_DB_PREFIX . 'admin_config';

        // Raw inserts with explicit string store_id so the SETUP itself never coerces
        // (INSERT does not compare against other rows, so it is safe pre-fix too).
        DB::connection(GP247_DB_CONNECTION)->table($table)->insert([
            ['group' => '', 'code' => 'zz_test', 'key' => self::TEST_KEY, 'value' => 'root-orig',  'security' => 0, 'store_id' => '1',                    'sort' => 0, 'detail' => ''],
            ['group' => '', 'code' => 'zz_test', 'key' => self::TEST_KEY, 'value' => 'store-orig', 'security' => 0, 'store_id' => self::STRING_STORE_ID, 'sort' => 0, 'detail' => ''],
        ]);

        // Exact query shape of ConfigForm::persistValue() base-scope write and the
        // reported crash: WHERE store_id = GP247_STORE_ID_ROOT.
        AdminConfig::where('key', self::TEST_KEY)
            ->where('group', '')
            ->where('store_id', GP247_STORE_ID_ROOT)
            ->update(['value' => 'GP247 System']);

        $root = DB::connection(GP247_DB_CONNECTION)->table($table)
            ->where('key', self::TEST_KEY)->where('store_id', '1')->value('value');
        $other = DB::connection(GP247_DB_CONNECTION)->table($table)
            ->where('key', self::TEST_KEY)->where('store_id', self::STRING_STORE_ID)->value('value');

        $this->assertSame('GP247 System', $root, 'ROOT row must be updated');
        $this->assertSame('store-orig', $other, 'the string-store_id row must be untouched');
    }
}
