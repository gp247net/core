<?php

namespace GP247\Core\PersonalData\Application\Requests;

use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Requests whose wait is over run without cron: after an administrator's request (terminating
 * hook), at most once a minute for the whole site, one due request is started and old history
 * is pruned. Errors are reported by class and never break the request that carried the tick.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-admin-create, US-personal-data-requests-audit-log
 * @aidlc-adr personal-data-requests_lazy-scheduling
 */
final class RequestScheduler
{
    private const LOCK = 'gp247:personal-data:request-tick';
    private const EVERY_SECONDS = 60;

    /** @var bool|null Memo: whether the request tables exist (site not updated yet). */
    private static ?bool $ready = null;

    /**
     * @return void
     */
    public static function afterRequest(): void
    {
        if (app()->runningInConsole()) {
            return;
        }
        try {
            if (!function_exists('admin') || admin()->user() === null) {
                return;
            }
            app(self::class)->tick();
        } catch (\Throwable $e) {
            if (function_exists('gp247_report')) {
                gp247_report('Personal data request tick failed: ' . $e::class);
            }
        }
    }

    /**
     * @param bool $force Skip the once-a-minute limit (tests, "run now").
     * @return string|null Id of the request started or checked.
     */
    public function tick(bool $force = false): ?string
    {
        if (!self::ready() || (!$force && !Cache::add(self::LOCK, 1, self::EVERY_SECONDS))) {
            return null;
        }
        $requests = app(RequestRepository::class);
        app(RequestLog::class)->prune($requests);
        if (\GP247\Core\PersonalData\Application\Export\ExportService::ready()) {
            // WHY here: packages downloaded or past their 24 hours leave the server without cron.
            app(\GP247\Core\PersonalData\Application\Export\ExportService::class)->prune();
        }

        return app(PrivacyRequests::class)->startDue();
    }

    /**
     * @return bool
     */
    public static function ready(): bool
    {
        return self::$ready ??= ErasureService::ready()
            && DB::connection(GP247_DB_CONNECTION)->getSchemaBuilder()->hasTable(PersonalDataSchema::table(PersonalDataSchema::REQUEST))
            && DB::connection(GP247_DB_CONNECTION)->getSchemaBuilder()->hasTable(PersonalDataSchema::table(PersonalDataSchema::REQUEST_LOG));
    }

    /**
     * @return void Forget the memo (tests, after gp247:update in the same process).
     */
    public static function forgetReady(): void
    {
        self::$ready = null;
    }
}
