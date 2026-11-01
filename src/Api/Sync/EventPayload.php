<?php

namespace GP247\Core\Api\Sync;

/**
 * The public shape of one sync event — the same in `GET api/v2/core/events` and in a
 * webhook body, so a receiver can match and de-duplicate them by `id`.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-event-read, US-api-sync-webhook-delivery
 */
final class EventPayload
{
    /**
     * @param object $row A row of the api_event table.
     * @return array<string, mixed>
     */
    public static function fromRow(object $row): array
    {
        return [
            'id'          => (int) $row->id,
            'type'        => $row->type,
            'subject_id'  => $row->subject_id,
            'store_id'    => $row->store_id,
            'source'      => $row->source,
            'actor'       => $row->actor_type ? ['type' => $row->actor_type, 'id' => $row->actor_id] : null,
            'data'        => json_decode((string) $row->data) ?: new \stdClass(),
            'occurred_at' => \Illuminate\Support\Carbon::parse($row->occurred_at)->toIso8601String(),
        ];
    }
}
