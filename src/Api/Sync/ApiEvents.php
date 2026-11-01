<?php

namespace GP247\Core\Api\Sync;

use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one way core, shop and plugins record a sync event. Thin rows (declared fields
 * only), written after the surrounding transaction commits — a rolled back change
 * leaves no event — and a failure to record is reported, never thrown, so it cannot
 * break the change. Nothing is written while the log is off (default) or for a type
 * nobody registered. Each recorded event is queued for the webhooks following its type.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-event-record
 * @aidlc-adr api-sync_event-log-after-commit
 */
final class ApiEvents
{
    /** admin_config key of the on/off switch (global, default off). */
    public const SWITCH_KEY = 'api_event_log';

    /** One in this many recorded events also runs a cleaning batch. */
    private const PRUNE_ODDS = 100;

    /** @var bool|null Memo of the schema probe (one per process). */
    private static ?bool $ready = null;

    /**
     * Whether the site owner turned the event log on.
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        try {
            return trim((string) (AdminConfig::getAllGlobal()[self::SWITCH_KEY] ?? '0')) === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the event table exists (false until gp247:update ran on an upgraded site).
     *
     * @return bool
     */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'api_event');
        }
        return self::$ready;
    }

    /**
     * Record one event of $type about $subjectId.
     *
     * @param string      $type      Registered type (EventType).
     * @param string      $subjectId Key of the changed record.
     * @param string|null $storeId   Store of the record.
     * @param array       $data      Values; only the fields declared for $type are kept.
     * @return void
     */
    public static function record(string $type, string $subjectId, ?string $storeId, array $data = []): void
    {
        try {
            $definition = EventType::definition($type);
            if ($definition === null || !self::enabled() || !self::ready()) {
                return;
            }
            [$source, $actorType, $actorId] = ApiEventSource::current();
            $row = [
                'type' => $type, 'subject_id' => mb_substr($subjectId, 0, 100), 'store_id' => $storeId,
                'source' => $source, 'actor_type' => $actorType, 'actor_id' => $actorId,
                'data' => json_encode((object) EventType::keep($definition['fields'], $data), JSON_THROW_ON_ERROR),
            ];
            DB::connection(GP247_DB_CONNECTION)->afterCommit(function () use ($row) {
                try {
                    $id = (int) DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_event')->insertGetId($row + ['occurred_at' => now()]);
                    WebhookDispatcher::enqueue($id, $row['type']);
                    if (random_int(1, self::PRUNE_ODDS) === 1) {
                        EventPruner::attempt();
                    }
                } catch (\Throwable $e) {
                    gp247_report($e);
                }
            });
        } catch (\Throwable $e) {
            gp247_report($e);
        }
    }
}
