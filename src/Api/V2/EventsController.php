<?php

namespace GP247\Core\Api\V2;

use GP247\Core\Api\Support\ApiErrorResponder;
use GP247\Core\Api\Support\ApiStoreScope;
use GP247\Core\Api\Sync\ApiEvents;
use GP247\Core\Api\Sync\EventPruner;
use GP247\Core\Api\Sync\EventType;
use GP247\Core\Controllers\RootAdminController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `GET api/v2/core/events` — the event log read by cursor (`after` = last id handled),
 * oldest first, limited to the types of the screens the admin may open and to the
 * admin's store. Events younger than the settle window are held back so two changes
 * committing at once never let a reader skip one.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-event-read, US-api-sync-event-retention
 * @aidlc-adr api-sync_event-log-after-commit
 */
class EventsController extends RootAdminController
{
    public const DEFAULT_PAGE_SIZE = 100;
    public const MAX_PAGE_SIZE = 500;

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws ValidationException On a bad cursor or type filter.
     */
    public function index(Request $request)
    {
        if (!ApiEvents::enabled() || !ApiEvents::ready()) {
            return ApiErrorResponder::respond(409, 'event_log_disabled', 'The event log is turned off on the API management screen');
        }
        EventPruner::attempt();

        $after = $request->query('after');
        if ($after !== null && (!is_string($after) || !ctype_digit($after))) {
            throw ValidationException::withMessages(['after' => ['after must be the id of the last event you handled.']]);
        }
        // WHY only with a cursor: a first read starts from the oldest event still kept.
        if ($after !== null && (int) $after < EventPruner::prunedUpto()) {
            return ApiErrorResponder::respond(410, 'cursor_expired', 'Events after this cursor were cleaned; resync with updated_since and read events from the start');
        }

        $types = EventType::readableBy($request->user());
        $filter = $request->query('filter');
        // WHY false for a scalar `filter`: it is refused below like any malformed type filter.
        $requested = is_array($filter) ? ($filter['type'] ?? null) : ($filter === null ? null : false);
        if ($requested !== null) {
            if (!is_string($requested)) {
                throw ValidationException::withMessages(['filter' => ['filter[type] is a comma-separated list of event types.']]);
            }
            $types = array_values(array_intersect($types, array_map('trim', explode(',', $requested))));
        }
        $page = (array) $request->query('page', []);
        $size = min(self::MAX_PAGE_SIZE, max(1, (int) ($page['size'] ?? self::DEFAULT_PAGE_SIZE)));

        $query = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_event')
            ->where('id', '>', (int) ($after ?? 0))
            ->whereIn('type', $types ?: ['-'])
            ->where('occurred_at', '<=', now()->subSeconds(max(0, (int) config('gp247-config.api.event_settle_seconds', 5))));
        $store = ApiStoreScope::current();
        if ($store !== (string) GP247_STORE_ID_ROOT) {
            $query->where('store_id', $store);
        }
        $rows = $query->orderBy('id')->limit($size + 1)->get();
        $hasMore = $rows->count() > $size;
        $rows = $rows->take($size)->values();

        return response()->json([
            'data' => $rows->map(fn ($row) => \GP247\Core\Api\Sync\EventPayload::fromRow($row))->values(),
            'meta' => [
                'per_page'   => $size,
                'next_after' => $rows->isNotEmpty() ? (int) $rows->last()->id : (int) ($after ?? 0),
                'has_more'   => $hasMore,
            ],
        ]);
    }
}
