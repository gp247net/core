<?php

namespace GP247\Core\PersonalData\Infrastructure;

use GP247\Core\PersonalData\Application\ErasureService;

/**
 * Runs one short slice of an unfinished erasure after a signed-in administrator's request
 * (the response is already on its way). Storefront and console requests never pay for it;
 * a failure here never reaches the administrator.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class ErasureResume
{
    /**
     * @return void
     */
    public static function afterRequest(): void
    {
        try {
            if (app()->runningInConsole() || !function_exists('admin') || admin()->user() === null) {
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
