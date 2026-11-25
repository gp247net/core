<?php

namespace GP247\Core\PersonalData\Application\Requests;

use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\Retention\RetentionSweeper;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Requests whose wait is over run without cron: after any web request (terminating hook), at
 * most once a minute for the whole site, one due request is started, guest requests never
 * verified are cancelled, the shop is reminded once of requests due soon, old history and
 * packages are pruned, and one slice of data past its retention period is cleaned. Errors are reported by class and never break the request that carried the tick.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-admin-create, US-personal-data-requests-audit-log, US-personal-data-requests-admin-notify, US-customer-privacy-guest-request, US-personal-data-retention-lazy-run
 * @aidlc-adr personal-data-requests_lazy-scheduling
 */
final class RequestScheduler
{
    private const LOCK = 'gp247:personal-data:request-tick';
    private const EVERY_SECONDS = 60;
    private const REMIND_DAYS = 3;

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
            $admin = function_exists('admin') && admin()->user() !== null;
            // WHY storefront too (once a minute at most): customers' own erasures and guests' requests
            // must move on shops whose administrator rarely signs in. Not where the visitor would
            // wait for it: without fastcgi_finish_request the response ends only after this work.
            if (!$admin && !self::finishesResponseFirst()) {
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
        // WHY the lock first: it is one cache write; checking the tables costs queries on every request.
        if ((!$force && !Cache::add(self::LOCK, 1, self::EVERY_SECONDS)) || !self::ready()) {
            return null;
        }
        $requests = app(RequestRepository::class);
        app(RequestLog::class)->prune($requests);
        app(GuestRequests::class)->processSubmissions();
        app(GuestRequests::class)->expire();
        $this->remindDueSoon($requests);
        if (\GP247\Core\PersonalData\Application\Export\ExportService::ready()) {
            // WHY here: packages downloaded or past their 24 hours leave the server without cron.
            app(\GP247\Core\PersonalData\Application\Export\ExportService::class)->prune();
        }
        $this->sweepRetention();

        return app(PrivacyRequests::class)->startDue();
    }

    /**
     * One slice of data past its retention period (US-personal-data-retention-lazy-run).
     *
     * @return void
     */
    private function sweepRetention(): void
    {
        try {
            if (RetentionSweeper::ready()) {
                app(RetentionSweeper::class)->tick();
            }
        } catch (\Throwable $e) {
            // WHY caught here: retention must never stop the requests' own steps that follow.
            if (function_exists('gp247_report')) {
                gp247_report('Personal data retention tick failed: ' . $e::class);
            }
        }
    }

    /**
     * Tell the shop once about each open request that is due within REMIND_DAYS.
     *
     * @param RequestRepository $requests
     * @return void
     */
    private function remindDueSoon(RequestRepository $requests): void
    {
        $log = app(RequestLog::class);
        foreach ($requests->dueBetween(now(), now()->addDays(self::REMIND_DAYS)) as $request) {
            $events = array_map(fn ($r) => (string) $r->event, $log->forRequest((string) $request->id));
            if (in_array('admin_reminded', $events, true)) {
                continue;
            }
            $log->append((string) $request->id, 'admin_reminded', ['type' => RequestLog::ACTOR_SYSTEM]);
            $due = now()->parse((string) $request->due_at);
            PrivacyNotices::send(fn ($mail) => $mail->adminDueSoon((string) $request->id, $due), (string) $request->store_id);
        }
    }

    /**
     * @return bool Whether the response reaches the visitor before terminating work runs (PHP-FPM, LiteSpeed).
     */
    public static function finishesResponseFirst(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
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
