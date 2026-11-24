<?php

namespace GP247\Core\PersonalData\Application\Requests;

use GP247\Core\PersonalData\Application\Pepper;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\SubjectLookup;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\Requests\RequestKind;
use GP247\Core\PersonalData\Domain\Requests\RequestSource;
use GP247\Core\PersonalData\Domain\Requests\RequestStatus;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Requests of shoppers without an account, made on the public form:
 *
 *  - the form only stores the submission (address encrypted) — the same work for every address,
 *    so neither the answer nor its timing tells whether an address has data, on any server;
 *  - the lazy scheduler then looks it up: an address with an account is told (at most once a day)
 *    to sign in instead, an address with no data is forgotten, otherwise the link that proves the
 *    address is sent; a site-wide hourly cap keeps the form from being used to mail many people;
 *  - after the link: an erasure waits for an administrator's approval, a data package is prepared.
 *
 * The address is kept encrypted while the request is open, and as a salted hash; it is removed
 * when the request closes. Submissions found to need nothing are deleted, not kept.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-customer-privacy-guest-request, US-personal-data-requests-guest-approval
 * @aidlc-adr personal-data-requests_request-model, customer-privacy_self-service-surfaces
 */
final class GuestRequests
{
    public const VERIFY_HOURS = 24;
    /** Emails the public form may cause in one hour, for the whole site. */
    public const MAILS_PER_HOUR = 30;
    private const MAIL_KEY = 'gp247:pd-guest-mail';
    private const BATCH = 20;

    /**
     * @param RequestRepository $requests
     * @param RequestWorkflow   $workflow
     * @param RequestLog        $log
     * @param SubjectLookup     $lookup
     * @param PrivacyExports    $exports
     */
    public function __construct(
        private readonly RequestRepository $requests,
        private readonly RequestWorkflow $workflow,
        private readonly RequestLog $log,
        private readonly SubjectLookup $lookup,
        private readonly PrivacyExports $exports,
    ) {
    }

    /**
     * Store a submission of the public form; nothing is looked up here.
     *
     * @param string $email
     * @param string $kind    export or erase.
     * @param string $storeId
     * @return void
     */
    public function submit(string $email, string $kind, string $storeId): void
    {
        $email = DataSubject::normalise($email);
        $kind = RequestKind::tryFrom($kind);
        if ($email === '' || !str_contains($email, '@') || $kind === null) {
            return;
        }
        $hash = Pepper::hashEmail($email);
        try {
            Cache::lock('gp247:pd-guest:' . $hash, 10)->block(5, function () use ($email, $hash, $kind, $storeId) {
                // WHY: one stored submission per address and kind until it is handled (an open request
                // is handled by the scheduler: its link is sent again, at most once an hour).
                if ($this->requests->uncheckedFor($hash, $kind->value)) {
                    return;
                }
                $received = now();
                $this->requests->create([
                    'kind' => $kind->value,
                    'source' => RequestSource::Guest->value,
                    'status' => RequestStatus::PendingVerification->value,
                    'email_hash' => $hash,
                    'email_enc' => gp247_secret_encrypt($email),
                    'store_id' => $storeId,
                    'received_at' => $received,
                    'due_at' => $received->copy()->addDays(PrivacySettings::overdueDays()),
                ]);
            });
        } catch (LockTimeoutException $e) {
            // WHY: a second submission for the same address at the same moment adds nothing.
        }
    }

    /**
     * Look up the stored submissions (lazy scheduler).
     *
     * @return int Submissions handled.
     */
    public function processSubmissions(): int
    {
        $done = 0;
        foreach ($this->requests->uncheckedGuests(self::BATCH) as $request) {
            if (RateLimiter::tooManyAttempts(self::MAIL_KEY, self::MAILS_PER_HOUR)) {
                // WHY: the rest waits for the next hour — the form must not mail many people at once.
                break;
            }
            $this->handle($request);
            $done++;
        }

        return $done;
    }

    /**
     * The shopper confirmed the link of their email (POST from the page the link opens).
     *
     * @param string $requestId
     * @return array{kind: string, status: string, export_id: string|null}|null Null when the link no longer applies.
     */
    public function verify(string $requestId): ?array
    {
        $request = $this->requests->find($requestId);
        if ($request === null || (string) $request->source !== RequestSource::Guest->value || $request->checked_at === null) {
            return null;
        }
        $status = (string) $request->status;
        if ($status !== RequestStatus::PendingVerification->value) {
            // WHY: a second confirmation shows where the request stands.
            return ['kind' => (string) $request->kind, 'status' => $status, 'export_id' => $this->exports->packageIdOf($requestId)];
        }
        if (now()->diffInHours($request->checked_at, true) >= self::VERIFY_HOURS) {
            $this->workflow->transition($request, RequestStatus::Cancelled, ['type' => RequestLog::ACTOR_SYSTEM], 'not_verified');

            return null;
        }
        $guest = ['type' => RequestLog::ACTOR_CUSTOMER, 'id' => null];
        if ((string) $request->kind === RequestKind::Erase->value) {
            $this->workflow->transition($request, RequestStatus::PendingApproval, $guest, null, ['verified' => true]);
            PrivacyNotices::send(fn ($mail) => $mail->adminApprovalNeeded($requestId), (string) $request->store_id);

            return ['kind' => 'erase', 'status' => RequestStatus::PendingApproval->value, 'export_id' => null];
        }
        $exportId = $this->exports->startForGuest($request, $guest);
        $now = $this->requests->find($requestId);

        return ['kind' => 'export', 'status' => (string) ($now->status ?? RequestStatus::Failed->value), 'export_id' => $exportId];
    }

    /**
     * @param string $requestId
     * @return object|null A guest request whose link may still be confirmed.
     */
    public function awaitingConfirmation(string $requestId): ?object
    {
        $request = $this->requests->find($requestId);

        return $request !== null && (string) $request->source === RequestSource::Guest->value && $request->checked_at !== null ? $request : null;
    }

    /**
     * Requests whose link was never opened within 24 hours are cancelled (lazy, from the scheduler).
     *
     * @return int
     */
    public function expire(): int
    {
        $done = 0;
        foreach ($this->requests->unverifiedBefore(now()->subHours(self::VERIFY_HOURS)) as $request) {
            try {
                $this->workflow->transition($request, RequestStatus::Cancelled, ['type' => RequestLog::ACTOR_SYSTEM], 'not_verified');
                $done++;
            } catch (\DomainException $e) {
                // WHY: verified meanwhile — the newer status stands.
            }
        }

        return $done;
    }

    /**
     * @param object $request A stored, not yet looked-up submission.
     * @return void
     */
    private function handle(object $request): void
    {
        $id = (string) $request->id;
        $email = PrivacyNotices::emailOf($request);
        $storeId = (string) $request->store_id;
        if ($email === null) {
            $this->requests->delete($id);

            return;
        }
        $hash = (string) $request->email_hash;
        if (PersonalDataRegistry::findCustomer($email) !== null) {
            $this->requests->delete($id);
            if (Cache::add('gp247:pd-signin:' . $hash, 1, 86400)) {
                RateLimiter::hit(self::MAIL_KEY, 3600);
                PrivacyNotices::send(fn ($mail) => $mail->signInInstead($email), $storeId);
            }

            return;
        }
        $open = $this->requests->openForEmail($hash, (string) $request->kind, includeUnchecked: false);
        if (!$this->hasData($email) || $open !== null) {
            $this->requests->delete($id);
            if ($open !== null && (string) $open->status === RequestStatus::PendingVerification->value
                && Cache::add('gp247:pd-verify:' . $open->id, 1, 3600)) {
                RateLimiter::hit(self::MAIL_KEY, 3600);
                PrivacyNotices::send(fn ($mail) => $mail->verifyGuest($email, (string) $open->id, (string) $open->kind), $storeId);
            }

            return;
        }
        $this->requests->update($id, ['checked_at' => now()]);
        $this->log->append($id, 'received', ['type' => RequestLog::ACTOR_SYSTEM], null, ['kind' => (string) $request->kind, 'source' => 'guest']);
        RateLimiter::hit(self::MAIL_KEY, 3600);
        // WHY marked: asking again within the hour does not send the link again.
        Cache::add('gp247:pd-verify:' . $id, 1, 3600);
        PrivacyNotices::send(fn ($mail) => $mail->verifyGuest($email, $id, (string) $request->kind), $storeId);
    }

    /**
     * @param string $email
     * @return bool Whether the site holds anything under this address.
     */
    private function hasData(string $email): bool
    {
        foreach ($this->lookup->counts(DataSubject::guest($email)) as $count) {
            if ((int) $count['rows'] > 0) {
                return true;
            }
        }

        return false;
    }
}
