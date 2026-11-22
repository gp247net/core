<?php

namespace GP247\Core\Tests\Feature\SecurityHeaders\Support;

use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Test helpers of the security-headers Unit: write a setting straight into the site-wide
 * config tier (inside the test transaction) and register throw-away routes in the
 * `web` / `api` groups so every response kind can be requested.
 *
 * @aidlc-unit security-headers
 */
trait ConfiguresSecurityHeaders
{
    /**
     * Store site-wide settings (key => value) and forget the per-request config memo.
     *
     * @param array<string, string|int> $values
     * @return void
     */
    protected function setSecuritySettings(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')->updateOrInsert(
                ['key' => $key, 'store_id' => (string) GP247_STORE_ID_GLOBAL],
                ['group' => 'global', 'code' => 'security_headers', 'value' => (string) $value, 'sort' => 0, 'detail' => '']
            );
        }
        AdminConfig::forgetMemo();
    }

    /**
     * Forget the static config memo: a rolled-back transaction does not reset it, so a
     * value written by an earlier test would otherwise leak into this one.
     *
     * @return void
     */
    protected function forgetConfigMemo(): void
    {
        AdminConfig::forgetMemo();
    }

    /**
     * Routes answering a page, JSON, a download and a page that sets its own frame header.
     *
     * @return void
     */
    protected function registerProbeRoutes(): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/__sh-test/page', fn () => response('<p>page</p>'));
            Route::get('/__sh-test/own-frame', fn () => response('<p>embed</p>')->header('X-Frame-Options', 'ALLOWALL'));
            Route::get('/__sh-test/download', fn () => response()->streamDownload(fn () => print('a,b'), 'x.csv'));
        });
        Route::middleware('api')->get('/__sh-test/api', fn () => response()->json(['ok' => true]));
        Route::get('/__sh-test/outside', fn () => response('<p>outside</p>'));
    }
}
