<?php

namespace GP247\Core\PersonalData\Application\Requests;

use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Domain\Requests\RequestKind;
use GP247\Core\PersonalData\Domain\Requests\RequestSource;
use GP247\Core\PersonalData\Domain\Requests\RequestStatus;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * A customer erases their own account (website or app): the request waits the days set for the
 * site so they can change their mind — from their account or with the link in the email — then
 * runs like any erasure (held while an order is still open). With a wait of 0 days it runs at once.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-customer-privacy-self-delete, US-customer-privacy-api
 * @aidlc-adr personal-data-requests_request-model, customer-privacy_self-service-surfaces
 */
final class CustomerErasures
{
    private const LOCK_SECONDS = 10;
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * @param RequestRepository $requests
     * @param RequestLog        $log
     * @param PrivacyRequests   $privacy
     */
    public function __construct(
        private readonly RequestRepository $requests,
        private readonly RequestLog $log,
        private readonly PrivacyRequests $privacy,
    ) {
    }

    /**
     * @param string                                $customerId
     * @param string                                $storeId
     * @param RequestSource                         $source customer or api.
     * @param array{type: string, id?: string|null} $actor
     * @return array{state: string, status: string, request_id: string, runs_at: string|null}
     *         state: scheduled (waits), held (an order is open), erasing (running now), failed.
     * @throws \RuntimeException When the site is busy (try again).
     */
    public function request(string $customerId, string $storeId, RequestSource $source, array $actor): array
    {
        $wait = PrivacySettings::waitDays();
        try {
            // WHY the same lock as an administrator's request: one open erasure per customer.
            [$id, $open] = Cache::lock('gp247:pd-request:' . $customerId, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn () => $this->openOrCreate($customerId, $storeId, $source, $actor, $wait));
        } catch (LockTimeoutException $e) {
            throw new \RuntimeException('Busy, try again.', 0, $e);
        }
        if ($open !== null) {
            $status = (string) $open->status;

            return ['state' => self::stateOf($status), 'status' => $status, 'request_id' => $id, 'runs_at' => $open->wait_until === null ? null : (string) $open->wait_until];
        }
        $runsAt = now()->addDays($wait);
        if ($wait === 0) {
            $result = $this->privacy->execute($id, $actor);
            $status = (string) ($this->requests->find($id)->status ?? $result['status']);

            return ['state' => self::stateOf($status), 'status' => $status, 'request_id' => $id, 'runs_at' => null];
        }
        $customer = PersonalDataRegistry::findCustomer($customerId);
        // WHY only a verified address: anyone can sign up with someone else's address and ask again
        // and again — the shop must not become a way to mail that person.
        if ($customer !== null && ($customer['verified'] ?? false) === true && !empty($customer['email'])) {
            $email = (string) $customer['email'];
            PrivacyNotices::send(fn ($mail) => $mail->erasureScheduled($email, $id, $runsAt), $storeId);
        }

        return ['state' => 'scheduled', 'status' => RequestStatus::Waiting->value, 'request_id' => $id, 'runs_at' => $runsAt->toDateTimeString()];
    }

    /**
     * @param string $status
     * @return string scheduled, held, erasing or failed.
     */
    private static function stateOf(string $status): string
    {
        return match ($status) {
            RequestStatus::OnHold->value => 'held',
            RequestStatus::Processing->value, RequestStatus::Completed->value => 'erasing',
            RequestStatus::Failed->value => 'failed',
            default => 'scheduled',
        };
    }

    /**
     * @param string $customerId
     * @return object|null The customer's erasure that has not run yet (waiting or held by an open order).
     */
    public function pendingFor(string $customerId): ?object
    {
        $open = $this->requests->openFor($customerId, RequestKind::Erase->value);

        return $open !== null && in_array((string) $open->status, [RequestStatus::Waiting->value, RequestStatus::OnHold->value], true) ? $open : null;
    }

    /**
     * @param string $requestId
     * @return object|null An erasure that has not run yet and can still be called off.
     */
    public function pendingById(string $requestId): ?object
    {
        $request = $this->requests->find($requestId);

        return $request !== null && (string) $request->kind === RequestKind::Erase->value
            && in_array((string) $request->status, [RequestStatus::Waiting->value, RequestStatus::OnHold->value], true) ? $request : null;
    }

    /**
     * Call off a waiting erasure: by the signed-in customer (their own only) or with the signed
     * link of the email (the link names the request).
     *
     * @param string                                $requestId
     * @param string|null                           $customerId Null when the signed link is used.
     * @param array{type: string, id?: string|null} $actor
     * @return bool Whether it was called off.
     */
    public function cancel(string $requestId, ?string $customerId, array $actor): bool
    {
        $request = $this->requests->find($requestId);
        if ($request === null || (string) $request->kind !== RequestKind::Erase->value
            || !in_array((string) $request->status, [RequestStatus::Waiting->value, RequestStatus::OnHold->value], true)
            || ($customerId !== null && (string) $request->customer_id !== $customerId)) {
            return false;
        }
        try {
            $this->privacy->cancel($requestId, $actor, 'changed_mind');
        } catch (\DomainException $e) {
            return false;
        }

        return true;
    }

    /**
     * @param string                                $customerId
     * @param string                                $storeId
     * @param RequestSource                         $source
     * @param array{type: string, id?: string|null} $actor
     * @param int                                   $wait
     * @return array{0: string, 1: object|null}
     */
    private function openOrCreate(string $customerId, string $storeId, RequestSource $source, array $actor, int $wait): array
    {
        $open = $this->requests->openFor($customerId, RequestKind::Erase->value);
        if ($open !== null) {
            return [(string) $open->id, $open];
        }
        $id = $this->requests->db()->transaction(function () use ($customerId, $storeId, $source, $actor, $wait) {
            $received = now();
            $id = $this->requests->create([
                'kind' => RequestKind::Erase->value,
                'source' => $source === RequestSource::Api ? RequestSource::Api->value : RequestSource::Customer->value,
                'status' => RequestStatus::Waiting->value,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'received_at' => $received,
                'due_at' => $received->copy()->addDays(PrivacySettings::overdueDays()),
                'wait_until' => $received->copy()->addDays($wait),
            ]);
            $this->log->append($id, 'received', $actor, null, ['kind' => 'erase', 'source' => $source->value, 'wait_days' => $wait]);

            return $id;
        });

        return [$id, null];
    }
}
