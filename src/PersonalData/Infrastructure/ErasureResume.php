<?php

namespace GP247\Core\PersonalData\Infrastructure;

use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use Illuminate\Support\Facades\Cache;

/**
 * Runs one short slice of an unfinished erasure or data package after a request (the response
 * is already on its way): after every signed-in administrator's request, and at most once every
 * 30 seconds after a storefront request served by PHP-FPM / LiteSpeed (the visitor never waits
 * for it) — customers erase and download on their own, on shops whose administrator rarely signs in. Console requests never pay for it; a failure here never
 * reaches the visitor.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise, US-customer-privacy-self-delete
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class ErasureResume
{
    private const STOREFRONT_LOCK = 'gp247:personal-data:resume';
    private const STOREFRONT_SECONDS = 30;

    /**
     * @return void
     */
    public static function afterRequest(): void
    {
        try {
            if (app()->runningInConsole()) {
                return;
            }
            $admin = function_exists('admin') && admin()->user() !== null;
            // WHY the cheap checks first: a storefront request pays one cache write at most, and none
            // where the visitor would wait for the slice (no fastcgi_finish_request).
            if (!$admin && (!RequestScheduler::finishesResponseFirst() || !Cache::add(self::STOREFRONT_LOCK, 1, self::STOREFRONT_SECONDS))) {
                return;
            }
            if (!ErasureService::ready()) {
                return;
            }
            app(ErasureService::class)->resumeOne();
        } catch (\Throwable $e) {
            if (function_exists('gp247_report')) {
                gp247_report('Personal data erasure resume failed: ' . get_class($e));
            }
        }
    }
}
