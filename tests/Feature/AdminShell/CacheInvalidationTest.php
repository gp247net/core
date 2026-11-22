<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use Illuminate\Support\Facades\Cache;
use GP247\Core\Tests\TestCase;

/**
 * Covers the version-bump cache invalidation contract owned by admin-shell
 * (Library/Helpers/cache.php): gp247_cache_version / gp247_cache_bump and the
 * routing in gp247_cache_clear. The `database`/array cache drivers cannot
 * wildcard-forget the per-store/locale keys, so clearing a group bumps a global
 * version that makes every old-version key unreachable.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-cache-config-hardening
 * @aidlc-adr admin-shell_cache-config-hardening
 */
class CacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate: drop any version markers/keys a previous test left behind
        // (cache is not wrapped by the DB transaction rollback).
        Cache::forget('gp247_cache_ver_category');
        Cache::forget('gp247_cache_ver_page');
        Cache::forget('cache_country');
    }

    public function test_version_defaults_to_one(): void
    {
        $this->assertSame(1, gp247_cache_version('category'));
        $this->assertSame(1, gp247_cache_version('page'));
    }

    public function test_bump_increments_version(): void
    {
        $this->assertSame(2, gp247_cache_bump('category'));
        $this->assertSame(2, gp247_cache_version('category'));
        $this->assertSame(3, gp247_cache_bump('category'));
        $this->assertSame(3, gp247_cache_version('category'));
    }

    public function test_clear_category_bumps_only_category(): void
    {
        $pageBefore = gp247_cache_version('page');

        gp247_cache_clear('cache_category');

        $this->assertSame(2, gp247_cache_version('category'));
        // Sibling group must be untouched (no cross-group invalidation).
        $this->assertSame($pageBefore, gp247_cache_version('page'));
    }

    public function test_clear_page_bumps_only_page(): void
    {
        $categoryBefore = gp247_cache_version('category');

        gp247_cache_clear('cache_page');

        $this->assertSame(2, gp247_cache_version('page'));
        $this->assertSame($categoryBefore, gp247_cache_version('category'));
    }

    public function test_clear_country_forgets_flat_key(): void
    {
        Cache::forever('cache_country', ['vn' => 'Vietnam']);

        gp247_cache_clear('cache_country');

        $this->assertFalse(Cache::has('cache_country'));
    }

    public function test_clear_all_is_scoped_and_never_flushes_unrelated_caches(): void
    {
        // An unrelated cache entry (e.g. SEO sitemap version, extension manager)
        // must survive — the old Cache::flush() would have wiped it.
        Cache::forever('gp247_sitemap_ver_0', 7);
        Cache::forever('some_unrelated_cache_key', 'keep-me');
        Cache::forever('cache_country', ['vn' => 'Vietnam']);

        gp247_cache_clear('cache_all');

        // Known GP247 groups are invalidated...
        $this->assertSame(2, gp247_cache_version('category'));
        $this->assertSame(2, gp247_cache_version('page'));
        $this->assertFalse(Cache::has('cache_country'));

        // ...but unrelated caches are left intact.
        $this->assertSame(7, Cache::get('gp247_sitemap_ver_0'));
        $this->assertSame('keep-me', Cache::get('some_unrelated_cache_key'));
    }

    public function test_clear_returns_success_envelope(): void
    {
        $response = gp247_cache_clear('cache_category');

        $this->assertSame(0, $response['error']);
        $this->assertSame('cache_category', $response['action']);
    }
}
