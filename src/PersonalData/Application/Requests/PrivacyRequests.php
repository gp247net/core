<?php

namespace GP247\Core\PersonalData\Application\Requests;

use GP247\Core\PersonalData\Application\ErasureLedger;
use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\Events\PersonalDataErased;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\Requests\RequestKind;
use GP247\Core\PersonalData\Domain\Requests\RequestSource;
use GP247\Core\PersonalData\Domain\Requests\RequestStatus;
use GP247\Core\PersonalData\Domain\Requests\RequestTransitions;
use GP247\Core\PersonalData\Infrastructure\JobRepository;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Entry point of personal data requests: every erasure an administrator asks for goes through a
 * request (with the wait for the customer to change their mind, or at once), every request is
 * started, held, cancelled or retried here, and closes when its erasure ends.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-admin-create, US-personal-data-requests-screen, US-customer-privacy-admin-delete-complete
 * @aidlc-adr personal-data-requests_request-model, personal-data-requests_lazy-scheduling
 */
final class PrivacyRequests
{
    public const BLOCKED = 'blocked';
    private const HOLD_RECHECK_MINUTES = 60;
    private const START_GRACE_MINUTES = 10;
    private const LOCK_SECONDS = 10;
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * @param RequestRepository $requests
     * @param RequestWorkflow   $workflow
     * @param RequestLog        $log
     * @param ErasureService    $erasures
     * @param JobRepository     $jobs
     * @param ErasureLedger     $ledger
     * @param PrivacyExports    $exports
     */
    public function __construct(
        private readonly RequestRepository $requests,
        private readonly RequestWorkflow $workflow,
        private readonly RequestLog $log,
        private readonly ErasureService $erasures,
        private readonly JobRepository $jobs,
        private readonly ErasureLedger $ledger,
        private readonly PrivacyExports $exports,
    ) {
    }

    /**
     * An administrator asks to erase a customer: at once, or after the wait set for the site.
     * At once and still blocked (an order in progress) ⇒ nothing is created, the blockers are
     * returned — the screen says why.
     *
     * @param string                                $customerId
     * @param bool                                  $now
     * @param string                                $storeId    Store of the customer (scope of the request).
     * @param array{type: string, id?: string|null} $actor
     * @param string|null                           $channel    How the customer asked (email, phone, store, other).
     * @param string|null                           $note
     * @return array{status: string, request_id?: string, job_id?: string|null, blockers?: list<array{reason: string, ref: string, label: string}>}
     */
    public function adminErase(string $customerId, bool $now, string $storeId, array $actor, ?string $channel = null, ?string $note = null): array
    {
        $subject = DataSubject::customer($customerId);
        if ($now) {
            $blockers = $this->erasures->blockers($subject);
            if ($blockers !== []) {
                return ['status' => self::BLOCKED, 'blockers' => $blockers];
            }
        }
        $wait = $now ? 0 : PrivacySettings::waitDays();
        // WHY a lock: one open erasure request per customer — two administrators (or a double
        // click) reading "none open" at the same moment would otherwise both create one.
        [$id, $open] = Cache::lock('gp247:pd-request:' . $customerId, self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, fn () => $this->openOrCreate($customerId, $storeId, $actor, $channel, $note, $wait));
        if ($open !== null) {
            // WHY: a second click (or a second administrator) follows the open request instead of
            // starting another.
            $status = RequestStatus::from((string) $open->status);
            if ($now && in_array($status, [RequestStatus::Waiting, RequestStatus::OnHold, RequestStatus::Failed], true)) {
                return $this->execute($id, $actor) + ['request_id' => $id];
            }

            return ['status' => $status->value, 'request_id' => $id, 'job_id' => $open->job_id === null ? null : (string) $open->job_id];
        }
        if (!$now) {
            return ['status' => RequestStatus::Waiting->value, 'request_id' => $id];
        }

        return $this->execute($id, $actor) + ['request_id' => $id];
    }

    /**
     * Run the erasure of a request now: held when an order is still in progress, otherwise the
     * erasure starts and its first slice runs.
     *
     * @param string                                $requestId
     * @param array{type: string, id?: string|null} $actor
     * @return array{status: string, job_id?: string|null, blockers?: list<array{reason: string, ref: string, label: string}>}
     * @throws \DomainException When the request cannot be run from its status.
     */
    public function execute(string $requestId, array $actor): array
    {
        $request = $this->mustFind($requestId);
        if ((string) $request->kind !== RequestKind::Erase->value) {
            // WHY: running or retrying a data package request must never erase the customer.
            throw new \DomainException('Only an erasure request can be run');
        }
        // WHY first: a request cancelled or completed meanwhile (a stale screen) must not reach
        // any side effect below — and one not yet proven or approved never runs.
        $from = RequestStatus::from((string) $request->status);
        if (!in_array($from, [RequestStatus::Waiting, RequestStatus::OnHold, RequestStatus::Failed], true)) {
            throw new \DomainException('This request cannot run from ' . $from->value);
        }
        RequestTransitions::assertAllowed($from, RequestStatus::Processing);
        $subject = $this->subjectOf($request);
        $matchEmails = $this->matchesEmails($request);
        $blockers = $this->erasures->blockers($subject, $matchEmails);
        if ($blockers !== []) {
            $this->hold($request, $actor, $blockers);

            return ['status' => RequestStatus::OnHold->value, 'blockers' => $blockers];
        }
        // WHY claim before the erasure: the conditional move refuses a request another admin or
        // the scheduler moved since it was read, so no erasure starts for a cancelled request.
        $request = $this->workflow->transition($request, RequestStatus::Processing, $actor, null, [], [
            'erasure_id' => null,
            'job_id' => null,
            'executed_at' => now(),
        ]);
        try {
            $outcome = $this->erasures->request($subject, $this->originOf($request), (string) ($actor['type'] ?? 'system'), isset($actor['id']) ? (string) $actor['id'] : null, runNow: false, matchEmails: $matchEmails, notify: $this->notifyAddress($request, $subject, $matchEmails));
        } catch (\Throwable $e) {
            $this->workflow->transition($request, RequestStatus::Failed, $actor, 'erasure_failed', ['error' => $e::class]);
            throw $e;
        }
        if ($outcome->isBlocked()) {
            $this->hold($request, $actor, $outcome->blockers);

            return ['status' => RequestStatus::OnHold->value, 'blockers' => $outcome->blockers];
        }
        $this->requests->update($requestId, ['erasure_id' => $outcome->erasureId, 'job_id' => $outcome->jobId]);
        // WHY after the erasure id is on the request: a first slice that ends the job sends
        // PersonalDataErased, which must find its request.
        $status = $outcome->jobId === null ? $outcome->status : $this->erasures->continue($outcome->jobId);

        return ['status' => $status, 'job_id' => $outcome->jobId];
    }

    /**
     * @param object                                                  $request
     * @param array{type: string, id?: string|null}                   $actor
     * @param list<array{reason: string, ref: string, label: string}> $blockers
     * @return void
     */
    private function hold(object $request, array $actor, array $blockers): void
    {
        $next = ['next_check_at' => now()->addMinutes(self::HOLD_RECHECK_MINUTES)];
        if ((string) $request->status === RequestStatus::OnHold->value) {
            $this->requests->update((string) $request->id, $next);

            return;
        }
        $this->workflow->transition($request, RequestStatus::OnHold, $actor, null, ['blockers' => array_column($blockers, 'reason')], $next);
    }

    /**
     * An administrator approves a guest erasure whose email was verified: it waits the days set
     * for the site (the shopper may still call it off with the link in their email), then runs.
     *
     * @param string                                $requestId
     * @param array{type: string, id?: string|null} $actor
     * @return void
     * @throws \DomainException When the request is not waiting for approval.
     */
    public function approve(string $requestId, array $actor): void
    {
        $request = $this->mustFind($requestId);
        $runsAt = now()->addDays(PrivacySettings::waitDays());
        $this->workflow->transition($request, RequestStatus::Waiting, $actor, null, [], ['wait_until' => $runsAt]);
        $email = PrivacyNotices::emailOf($request);
        if ($email !== null) {
            PrivacyNotices::send(fn ($mail) => $mail->erasureScheduled($email, $requestId, $runsAt), (string) $request->store_id);
        }
    }

    /**
     * An administrator turns down a guest erasure; the shopper is told why.
     *
     * @param string                                $requestId
     * @param array{type: string, id?: string|null} $actor
     * @param string                                $reason
     * @return void
     * @throws \DomainException When the reason is empty or the request is not waiting for approval.
     */
    public function reject(string $requestId, array $actor, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new \DomainException('A reason is required');
        }
        $request = $this->mustFind($requestId);
        // WHY read first: closing the request removes the encrypted address.
        $email = PrivacyNotices::emailOf($request);
        $this->workflow->transition($request, RequestStatus::Rejected, $actor, $reason);
        if ($email !== null) {
            PrivacyNotices::send(fn ($mail) => $mail->rejected($email, $reason), (string) $request->store_id);
        }
    }

    /**
     * @param string                                $requestId
     * @param array{type: string, id?: string|null} $actor
     * @param string|null                           $reason
     * @return void
     * @throws \DomainException When the request can no longer be cancelled.
     */
    public function cancel(string $requestId, array $actor, ?string $reason = null): void
    {
        $this->workflow->transition($this->mustFind($requestId), RequestStatus::Cancelled, $actor, $reason);
    }

    /**
     * A failed erasure, run again.
     *
     * @param string                                $requestId
     * @param array{type: string, id?: string|null} $actor
     * @return array{status: string, job_id?: string|null, blockers?: list<array{reason: string, ref: string, label: string}>}
     */
    public function retry(string $requestId, array $actor): array
    {
        $request = $this->mustFind($requestId);
        if ((string) $request->status !== RequestStatus::Failed->value) {
            throw new \DomainException('Only a failed request can be run again');
        }

        return $this->execute($requestId, $actor);
    }

    /**
     * One lazy step (RequestScheduler): a request whose job gave up is marked failed, then at
     * most one waiting request whose wait is over — or one request on hold due for a check — is run.
     *
     * @return string|null Id of the request started or checked, if any.
     */
    public function startDue(): ?string
    {
        $system = ['type' => RequestLog::ACTOR_SYSTEM];
        foreach ($this->requests->processing() as $request) {
            try {
                $this->reconcile($request, $system);
            } catch (\DomainException $e) {
                // WHY: moved by someone else meanwhile — nothing left to reconcile.
            }
        }
        $id = $this->requests->nextDue(now()) ?? $this->requests->nextOnHold(now());
        if ($id === null) {
            return null;
        }
        try {
            $this->execute($id, $system);
        } catch (\Throwable $e) {
            // WHY: a request that cannot start must not be picked again on every tick and hold
            // the queue — it is marked failed for an administrator to look at.
            $this->report('Personal data request could not start: ' . $e::class);
            $request = $this->requests->find($id);
            if ($request !== null && RequestTransitions::allows(RequestStatus::from((string) $request->status), RequestStatus::Failed)) {
                try {
                    $this->workflow->transition($request, RequestStatus::Failed, $system, 'erasure_failed', ['error' => $e::class]);
                } catch (\DomainException $ignored) {
                    // WHY: moved meanwhile — the newer status stands.
                }
            }
        }

        return $id;
    }

    /**
     * A request being processed whose erasure ended without telling it (the job gave up, the
     * event was lost, the job was pruned) is moved to the status its erasure really reached.
     *
     * @param object                                $request
     * @param array{type: string, id?: string|null} $system
     * @return void
     */
    private function reconcile(object $request, array $system): void
    {
        if ((string) $request->kind === RequestKind::Export->value) {
            $this->exports->reconcile($request, $system);

            return;
        }
        if ($request->job_id === null || (string) $request->job_id === '') {
            // WHY: claimed, then the process died before the erasure was created.
            if ($request->executed_at === null || now()->diffInMinutes($request->executed_at, true) >= self::START_GRACE_MINUTES) {
                $this->workflow->transition($request, RequestStatus::Failed, $system, 'erasure_not_started');
            }

            return;
        }
        $job = $this->jobs->find((string) $request->job_id);
        $jobStatus = $job === null ? null : (string) $job->status;
        if ($jobStatus === JobRepository::FAILED) {
            $this->workflow->transition($request, RequestStatus::Failed, $system, 'erasure_failed');

            return;
        }
        if ($jobStatus !== null && $jobStatus !== JobRepository::DONE) {
            return;
        }
        $outcome = $request->erasure_id === null ? null : $this->ledger->outcome((string) $request->erasure_id);
        if ($outcome !== null) {
            $this->workflow->transition($request, RequestStatus::Completed, $system, null, ['rows' => $outcome]);
        } elseif ($job === null) {
            $this->workflow->transition($request, RequestStatus::Failed, $system, 'erasure_missing');
        }
    }

    /**
     * Listener: the erasure of a request ended ⇒ the request is completed.
     *
     * @param PersonalDataErased $event
     * @return void
     */
    public function erased(PersonalDataErased $event): void
    {
        if (!RequestScheduler::ready()) {
            // WHY: a site whose request tables are not created yet still erases (erasure tables only).
            return;
        }
        $request = $this->requests->findByErasure($event->erasureId);
        if ($request === null || (string) $request->status !== RequestStatus::Processing->value) {
            return;
        }
        $this->workflow->transition($request, RequestStatus::Completed, ['type' => RequestLog::ACTOR_SYSTEM], null, ['rows' => $event->counts]);
        // WHY only when the person asked: an erasure an administrator ran is announced by them.
        $email = $event->emails[0] ?? null;
        if ($email !== null && (string) $request->source !== RequestSource::Admin->value) {
            PrivacyNotices::send(fn ($mail) => $mail->erased($email), (string) $request->store_id);
        }
    }

    /**
     * @param string                                $customerId
     * @param string                                $storeId
     * @param array{type: string, id?: string|null} $actor
     * @param string|null                           $channel
     * @param string|null                           $note
     * @param int                                   $wait
     * @return array{0: string, 1: object|null} Request id, and the open request when one existed.
     */
    private function openOrCreate(string $customerId, string $storeId, array $actor, ?string $channel, ?string $note, int $wait): array
    {
        $open = $this->requests->openFor($customerId, RequestKind::Erase->value);
        if ($open !== null) {
            return [(string) $open->id, $open];
        }
        $id = $this->requests->db()->transaction(function () use ($customerId, $storeId, $actor, $channel, $note, $wait) {
            $received = now();
            $id = $this->requests->create([
                'kind' => RequestKind::Erase->value,
                'source' => RequestSource::Admin->value,
                'channel' => in_array($channel, RequestSource::ADMIN_CHANNELS, true) ? $channel : null,
                'status' => RequestStatus::Waiting->value,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'received_at' => $received,
                'due_at' => $received->copy()->addDays(PrivacySettings::overdueDays()),
                'wait_until' => $received->copy()->addDays($wait),
                'created_by' => isset($actor['id']) ? (string) $actor['id'] : null,
                'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 1000),
            ]);
            $this->log->append($id, 'received', $actor, null, ['kind' => 'erase', 'source' => 'admin', 'wait_days' => $wait]);

            return $id;
        });

        return [$id, null];
    }

    /**
     * @param string $message Class names only — never personal data.
     * @return void
     */
    private function report(string $message): void
    {
        if (function_exists('gp247_report')) {
            gp247_report($message);
        }
    }

    /**
     * @param string $requestId
     * @return object
     * @throws \DomainException When the request does not exist.
     */
    private function mustFind(string $requestId): object
    {
        return $this->requests->find($requestId) ?? throw new \DomainException('Unknown request');
    }

    /**
     * @param object $request
     * @return DataSubject
     * @throws \DomainException When the request names no subject (a guest request with no customer account).
     */
    private function subjectOf(object $request): DataSubject
    {
        if ($request->customer_id !== null && (string) $request->customer_id !== '') {
            return DataSubject::customer((string) $request->customer_id);
        }
        $email = PrivacyNotices::emailOf($request);
        if ((string) $request->source === RequestSource::Guest->value && $email !== null) {
            return DataSubject::guest($email);
        }
        throw new \DomainException('This request names no customer');
    }

    /**
     * @param object      $request
     * @param DataSubject $subject
     * @param bool        $matchEmails
     * @return string|null Where to say "your data is erased" (kept only by the erasure, never stored):
     *                     the proven address of the person who asked; none when an administrator asked.
     */
    private function notifyAddress(object $request, DataSubject $subject, bool $matchEmails): ?string
    {
        $source = (string) $request->source;
        if ($source === RequestSource::Guest->value) {
            return $subject->emails[0] ?? null;
        }
        if (!$matchEmails || !in_array($source, [RequestSource::Customer->value, RequestSource::Api->value], true) || empty($request->customer_id)) {
            return null;
        }
        $email = PersonalDataRegistry::findCustomer((string) $request->customer_id)['email'] ?? null;

        return $email === null || $email === '' ? null : (string) $email;
    }

    /**
     * @param object $request
     * @return bool Whether rows found by the account's email are part of the erasure: always when an
     *              administrator asked, only for a verified email when the customer asked (anyone can
     *              sign up with someone else's address) — checked when the erasure runs.
     */
    private function matchesEmails(object $request): bool
    {
        if (!in_array((string) $request->source, [RequestSource::Customer->value, RequestSource::Api->value], true) || empty($request->customer_id)) {
            return true;
        }
        $customer = PersonalDataRegistry::findCustomer((string) $request->customer_id);

        return $customer !== null && ($customer['verified'] ?? false) === true;
    }

    /**
     * @param object $request
     * @return string ErasureService origin.
     */
    private function originOf(object $request): string
    {
        return match ((string) $request->source) {
            RequestSource::Customer->value, RequestSource::Api->value => 'customer',
            RequestSource::Guest->value => 'guest',
            default => 'admin',
        };
    }
}
