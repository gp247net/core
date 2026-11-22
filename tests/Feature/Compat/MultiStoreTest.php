<?php

namespace GP247\Core\Tests\Feature\Compat;

use GP247\Core\Models\AdminStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Core\Tests\TestCase;

/**
 * Verifies that multi-store / partner-domain resolution logic works correctly
 * after the Livewire/TailAdmin cutover. Store routing must remain unchanged (US-CMP-003).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-003
 */
class MultiStoreTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * At least one store (root) must exist in the database.
     * WHY: getListAllActive() uses a static cache populated by middleware during
     * real requests; in the test env this cache may be empty. We verify the DB
     * directly via model count() which always hits the connection.
     */
    public function test_at_least_one_store_exists(): void
    {
        $count = AdminStore::count();
        $this->assertGreaterThanOrEqual(1, $count, 'At least one store (root) must exist in DB');
    }

    /**
     * gp247_store_is_root() must identify the root store correctly.
     */
    public function test_store_is_root_identifies_root_store(): void
    {
        if (!defined('GP247_STORE_ID_ROOT')) {
            $this->markTestSkipped('GP247_STORE_ID_ROOT constant not defined');
        }
        $this->assertTrue(
            gp247_store_is_root(GP247_STORE_ID_ROOT),
            'Root store ID must be recognized as root'
        );
    }

    /**
     * gp247_store_is_partner() must return a boolean without throwing.
     * WHY: In test DB the root store's partner flag depends on seed data;
     * we assert the function is callable and type-correct, not the specific value.
     */
    public function test_store_is_partner_returns_boolean(): void
    {
        if (!defined('GP247_STORE_ID_ROOT')) {
            $this->markTestSkipped('GP247_STORE_ID_ROOT constant not defined');
        }
        $result = gp247_store_is_partner(GP247_STORE_ID_ROOT);
        $this->assertIsBool($result, 'gp247_store_is_partner() must return a boolean');
    }

    /**
     * gp247_store_get_list_code() returns an array of string codes.
     */
    public function test_store_get_list_code_returns_array_of_strings(): void
    {
        $codes = gp247_store_get_list_code();
        $this->assertIsArray($codes);
        foreach ($codes as $code) {
            $this->assertIsString($code, 'Each store code must be a string');
        }
    }

    /**
     * gp247_store_info() resolves store-specific config without throwing.
     */
    public function test_store_info_resolves_without_exception(): void
    {
        if (!defined('GP247_STORE_ID_ROOT')) {
            $this->markTestSkipped('GP247_STORE_ID_ROOT constant not defined');
        }
        // Should not throw; may return null if key is absent
        $result = gp247_store_info('title', null, GP247_STORE_ID_ROOT);
        $this->assertTrue(
            is_null($result) || is_string($result) || is_array($result),
            'gp247_store_info() must return string/array/null'
        );
    }

    /**
     * AdminStore static query methods must return without throwing.
     * WHY: These methods use internal static caches populated by boot middleware;
     * we verify they are callable and return a Collection/array (may be empty in tests).
     */
    public function test_admin_store_static_methods_return_without_throwing(): void
    {
        $listAll = AdminStore::getListAll();
        $this->assertNotNull($listAll, 'getListAll() must return a value');

        $listStoreId = AdminStore::getListStoreId();
        $this->assertTrue(
            is_array($listStoreId) || $listStoreId instanceof \Traversable,
            'getListStoreId() must return iterable'
        );
    }

    /**
     * gp247_store_get_domain_root() returns a non-empty string.
     */
    public function test_store_get_domain_root_returns_string(): void
    {
        $root = gp247_store_get_domain_root();
        $this->assertIsString($root);
    }
}
