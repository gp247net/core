<?php

namespace GP247\Core\PersonalData\Application\Requests;

use GP247\Core\PersonalData\Domain\Requests\RequestKind;
use GP247\Core\PersonalData\Domain\Requests\RequestStatus;
use GP247\Core\PersonalData\Domain\Requests\RequestTransitions;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestRepository;

/**
 * The only place a request changes status: the move must be allowed, and the history row is
 * written in the same transaction. When an erasure request closes, the free-text note and the
 * encrypted email are removed — the request keeps ids and dates only.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-screen, US-personal-data-requests-audit-log
 * @aidlc-adr personal-data-requests_request-model, personal-data-requests_request-log
 */
final class RequestWorkflow
{
    /**
     * @param RequestRepository $requests
     * @param RequestLog        $log
     */
    public function __construct(private readonly RequestRepository $requests, private readonly RequestLog $log)
    {
    }

    /**
     * @param object                                $request A row of personal_data_request.
     * @param RequestStatus                         $to
     * @param array{type: string, id?: string|null} $actor
     * @param string|null                           $reason
     * @param array<string, mixed>                  $context Codes and counts for the history.
     * @param array<string, mixed>                  $changes Other columns to set with the move.
     * @return object The request as it is now.
     * @throws \DomainException When the move is not allowed.
     */
    public function transition(object $request, RequestStatus $to, array $actor, ?string $reason = null, array $context = [], array $changes = []): object
    {
        $from = RequestStatus::from((string) $request->status);
        RequestTransitions::assertAllowed($from, $to);
        if ($to->isClosed()) {
            $changes['closed_at'] = now();
            $changes['email_enc'] = null;
            if ((string) $request->kind === RequestKind::Erase->value) {
                // WHY: an administrator's note may name the customer; it must not outlive the erasure.
                $changes['note'] = null;
            }
        }
        $this->requests->db()->transaction(function () use ($request, $to, $from, $actor, $reason, $context, $changes) {
            // WHY conditional: another admin or the scheduler may have moved the request since it was
            // read (e.g. cancelled) — writing over that would run an erasure after a cancel.
            if ($this->requests->updateIfStatus((string) $request->id, $from->value, ['status' => $to->value] + $changes) === 0) {
                throw new \DomainException('The request changed meanwhile');
            }
            $this->log->append((string) $request->id, 'status:' . $to->value, $actor, $reason, ['from' => $from->value] + $context);
        });

        return $this->requests->find((string) $request->id) ?? $request;
    }
}
