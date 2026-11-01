<?php

namespace GP247\Core\Api\Sync;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lazy cleaning of the event log — no cron: one batch of expired events per attempt,
 * guarded by a short cache lock so two requests never clean at once. The highest
 * cleaned id is kept as a mark so a cursor older than it is told to resync.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-event-retention
 * @aidlc-adr api-sync_event-log-after-commit
 */
final class EventPruner
{
    public const LOCK = 'gp247:api-event-prune';
    public const MARK_KEY = 'api_event_pruned_upto';
    private const BATCH = 1000;

    /**
     * Days an event is kept.
     *
     * @return int
     */
    public static function keepDays(): int
    {
        return max(1, (int) config('gp247-config.api.event_days', 30));
    }

    /**
     * Delete one batch of expired events; errors are reported, never thrown.
     *
     * @return void
     */
    public static function attempt(): void
    {
        try {
            if (!ApiEvents::ready() || !Cache::add(self::LOCK, 1, 60)) {
                return;
            }
            try {
                $ids = self::events()->where('occurred_at', '<', now()->subDays(self::keepDays()))
                    ->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
                if ($ids) {
                    self::events()->whereIn('id', $ids)->delete();
                    $upto = (int) max($ids);
                    if ($upto > self::prunedUpto()) {
                        self::config()->updateOrInsert(
                            ['key' => self::MARK_KEY, 'store_id' => GP247_STORE_ID_GLOBAL],
                            ['group' => 'global', 'code' => 'api_config', 'value' => (string) $upto, 'sort' => 99, 'detail' => '']
                        );
                    }
                }
                self::pruneDeliveries();
            } finally {
                Cache::forget(self::LOCK);
            }
        } catch (\Throwable $e) {
            gp247_report($e);
        }
    }

    /**
     * Finished webhook deliveries (delivered or given up) older than the event keep period, one batch.
     *
     * @return void
     *
     * @aidlc-story US-api-sync-webhook-delivery
     */
    private static function pruneDeliveries(): void
    {
        $db = \Illuminate\Support\Facades\DB::connection(GP247_DB_CONNECTION);
        if (!$db->getSchemaBuilder()->hasTable(GP247_DB_PREFIX . 'api_webhook_delivery')) {
            return;
        }
        $ids = $db->table(GP247_DB_PREFIX . 'api_webhook_delivery')->where('status', '<>', 'pending')
            ->where('updated_at', '<', now()->subDays(self::keepDays()))->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
        if ($ids) {
            $db->table(GP247_DB_PREFIX . 'api_webhook_delivery')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Highest event id cleaned so far (0 = nothing cleaned).
     *
     * @return int
     */
    public static function prunedUpto(): int
    {
        return (int) self::config()->where('key', self::MARK_KEY)->where('store_id', GP247_STORE_ID_GLOBAL)->value('value');
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private static function events()
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_event');
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private static function config()
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
    }
}
