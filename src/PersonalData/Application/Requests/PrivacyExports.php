<?php

namespace GP247\Core\PersonalData\Application\Requests;

use GP247\Core\PersonalData\Application\Events\PersonalDataExportReady;
use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\Requests\RequestKind;
use GP247\Core\PersonalData\Domain\Requests\RequestSource;
use GP247\Core\PersonalData\Domain\Requests\RequestStatus;
use GP247\Core\PersonalData\Infrastructure\Export\ExportRepository;
use GP247\Core\PersonalData\Infrastructure\JobRepository;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Data package requests: a customer (on the web or through the API) or an administrator asks
 * for the customer's data. The request goes straight to "processing" (nothing to wait for), the
 * package is built in slices, and the request is completed when the package is ready. One
 * package per customer per 24 hours: asking again returns the package already made.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-customer-privacy-self-export, US-personal-data-requests-admin-create, US-personal-data-requests-audit-log
 * @aidlc-adr personal-data-requests_request-model
 */
final class PrivacyExports
{
    private const LOCK_SECONDS = 10;
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * @param RequestRepository $requests
     * @param RequestWorkflow   $workflow
     * @param RequestLog        $log
     * @param ExportService     $exports
     * @param ExportRepository  $packages
     * @param JobRepository     $jobs
     */
    public function __construct(
        private readonly RequestRepository $requests,
        private readonly RequestWorkflow $workflow,
        private readonly RequestLog $log,
        private readonly ExportService $exports,
        private readonly ExportRepository $packages,
        private readonly JobRepository $jobs,
    ) {
    }

    /**
     * @param string                                $customerId
     * @param string                                $storeId
     * @param RequestSource                         $source customer, api or admin.
     * @param array{type: string, id?: string|null} $actor
     * @param bool                                  $emailVerified Whether the account's email is proven to be the
     *                                                             customer's (rows found by email are added only then).
     * @return array{request_id: string|null, export_id: string, status: string, reused: bool}
     * @throws \RuntimeException When this server cannot build packages (no ZIP, site not updated) or is busy.
     */
    public function request(string $customerId, string $storeId, RequestSource $source, array $actor, bool $emailVerified = true): array
    {
        if (!ExportService::ready()) {
            throw new \RuntimeException('Data packages cannot be built on this server.');
        }
        $this->exports->pruneSometimes();
        // WHY separate: the administrator answering an email and the customer on the website must
        // not use up each other's package.
        $audience = $source === RequestSource::Admin ? ExportRepository::AUDIENCE_ADMIN : ExportRepository::AUDIENCE_CUSTOMER;
        try {
            // WHY a lock: two clicks (or the web and the app at once) must not build two packages.
            return Cache::lock('gp247:pd-export:' . $audience . ':' . $customerId, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn () => $this->openOrReuse($customerId, $storeId, $source, $actor, $emailVerified, $audience));
        } catch (LockTimeoutException $e) {
            throw new \RuntimeException('Busy, try again.', 0, $e);
        }
    }

    /**
     * @param string                                $customerId
     * @param string                                $storeId
     * @param RequestSource                         $source
     * @param array{type: string, id?: string|null} $actor
     * @param bool                                  $emailVerified
     * @param string                                $audience
     * @return array{request_id: string|null, export_id: string, status: string, reused: bool}
     */
    private function openOrReuse(string $customerId, string $storeId, RequestSource $source, array $actor, bool $emailVerified, string $audience): array
    {
        $recent = $this->exports->recentFor($customerId, $audience);
        if ($recent !== null) {
            return ['request_id' => $recent->request_id, 'export_id' => (string) $recent->id, 'status' => (string) $recent->status, 'reused' => true];
        }
        $requestId = $this->open($customerId, $storeId, $source, $actor);
        try {
            $exportId = $this->exports->start(DataSubject::customer($customerId), $requestId, $audience, (string) ($actor['type'] ?? 'system'), isset($actor['id']) ? (string) $actor['id'] : null, $emailVerified, $audience);
        } catch (\Throwable $e) {
            $request = $this->requests->find($requestId);
            if ($request !== null) {
                $this->workflow->transition($request, RequestStatus::Failed, $actor, 'export_failed', ['error' => $e::class]);
            }
            throw $e;
        }
        $this->requests->update($requestId, ['job_id' => $this->packages->find($exportId)->job_id ?? null]);

        return ['request_id' => $requestId, 'export_id' => $exportId, 'status' => ExportRepository::BUILDING, 'reused' => false];
    }

    /**
     * Listener: the package of a request is ready ⇒ the request is completed.
     *
     * @param PersonalDataExportReady $event
     * @return void
     */
    public function ready(PersonalDataExportReady $event): void
    {
        if ($event->requestId === null || !RequestScheduler::ready()) {
            return;
        }
        $request = $this->requests->find($event->requestId);
        if ($request === null || (string) $request->status !== RequestStatus::Processing->value) {
            return;
        }
        // WHY before closing: closing the request removes the encrypted address of a guest.
        $notify = $this->readyNotice($request, $event->exportId);
        $this->workflow->transition($request, RequestStatus::Completed, ['type' => RequestLog::ACTOR_SYSTEM], null, ['rows' => $event->counts]);
        if ($notify !== null) {
            PrivacyNotices::send($notify, (string) $request->store_id);
        }
    }

    /**
     * A shopper without an account proved their address: their package is prepared (rows found by
     * that address only).
     *
     * @param object                                $request A guest export request waiting for its link.
     * @param array{type: string, id?: string|null} $actor
     * @return string|null Package id, or null when it cannot be made on this server.
     */
    public function startForGuest(object $request, array $actor): ?string
    {
        $email = PrivacyNotices::emailOf($request);
        if ($email === null || !ExportService::ready()) {
            return null;
        }
        $this->workflow->transition($request, RequestStatus::Processing, $actor, null, ['verified' => true], ['executed_at' => now()]);
        try {
            $exportId = $this->exports->start(DataSubject::guest($email), (string) $request->id, 'guest', 'guest', null, true, ExportRepository::AUDIENCE_GUEST);
        } catch (\Throwable $e) {
            $current = $this->requests->find((string) $request->id);
            if ($current !== null) {
                $this->workflow->transition($current, RequestStatus::Failed, $actor, 'export_failed', ['error' => $e::class]);
            }

            return null;
        }
        $this->requests->update((string) $request->id, ['job_id' => $this->packages->find($exportId)->job_id ?? null]);
        $this->exports->continue($exportId);

        return $exportId;
    }

    /**
     * @param string $requestId
     * @return string|null The package of a request, if one was made.
     */
    public function packageIdOf(string $requestId): ?string
    {
        $package = $this->packages->forRequest($requestId);

        return $package === null ? null : (string) $package->id;
    }

    /**
     * @param object $request
     * @param string $exportId
     * @return \Closure|null The "your data is ready" email to send once the request is closed.
     */
    private function readyNotice(object $request, string $exportId): ?\Closure
    {
        $source = (string) $request->source;
        if ($source === RequestSource::Guest->value) {
            $email = PrivacyNotices::emailOf($request);

            return $email === null ? null : fn ($mail) => $mail->exportReady($email, (string) $request->id, $exportId, true);
        }
        if (in_array($source, [RequestSource::Customer->value, RequestSource::Api->value], true) && !empty($request->customer_id)) {
            $email = PersonalDataRegistry::findCustomer((string) $request->customer_id)['email'] ?? null;

            return $email === null || $email === '' ? null : fn ($mail) => $mail->exportReady((string) $email, (string) $request->id, $exportId, false);
        }

        return null;
    }

    /**
     * Record that a package was downloaded (the history of its request).
     *
     * @param object                                $package A row of personal_data_export.
     * @param array{type: string, id?: string|null} $actor
     * @return void
     */
    public function downloaded(object $package, array $actor): void
    {
        if ($package->request_id !== null && RequestScheduler::ready()) {
            $this->log->append((string) $package->request_id, 'export_downloaded', $actor, null, ['export' => (string) $package->id]);
        }
    }

    /**
     * A request being processed whose package ended without telling it is moved to the status
     * its package really reached.
     *
     * @param object                                $request
     * @param array{type: string, id?: string|null} $system
     * @return void
     */
    public function reconcile(object $request, array $system): void
    {
        $package = $this->packages->forRequest((string) $request->id);
        if ($package === null) {
            $this->workflow->transition($request, RequestStatus::Failed, $system, 'export_missing');

            return;
        }
        $status = (string) $package->status;
        if ($status === ExportRepository::BUILDING) {
            $job = $package->job_id === null ? null : $this->jobs->find((string) $package->job_id);
            if ($job === null || (string) $job->status === JobRepository::FAILED) {
                $this->packages->update((string) $package->id, ['status' => ExportRepository::FAILED]);
                $this->workflow->transition($request, RequestStatus::Failed, $system, 'export_failed');
            }

            return;
        }
        if ($status === ExportRepository::FAILED) {
            $this->workflow->transition($request, RequestStatus::Failed, $system, 'export_failed');

            return;
        }
        $this->workflow->transition($request, RequestStatus::Completed, $system);
    }

    /**
     * @param string                                $customerId
     * @param string                                $storeId
     * @param RequestSource                         $source
     * @param array{type: string, id?: string|null} $actor
     * @return string Request id.
     */
    private function open(string $customerId, string $storeId, RequestSource $source, array $actor): string
    {
        return $this->requests->db()->transaction(function () use ($customerId, $storeId, $source, $actor) {
            $received = now();
            $id = $this->requests->create([
                'kind' => RequestKind::Export->value,
                'source' => $source->value,
                'status' => RequestStatus::Processing->value,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'received_at' => $received,
                'due_at' => $received->copy()->addDays(PrivacySettings::overdueDays()),
                'wait_until' => $received,
                'executed_at' => $received,
                'created_by' => $source === RequestSource::Admin && isset($actor['id']) ? (string) $actor['id'] : null,
            ]);
            $this->log->append($id, 'received', $actor, null, ['kind' => 'export', 'source' => $source->value]);

            return $id;
        });
    }
}
