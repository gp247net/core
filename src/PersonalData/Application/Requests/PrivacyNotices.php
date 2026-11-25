<?php

namespace GP247\Core\PersonalData\Application\Requests;

use Closure;
use GP247\Core\PersonalData\Domain\Requests\PrivacyMailer;

/**
 * Sends a request email without ever breaking the request: a mail server that refuses, or a
 * view that fails, is reported (by class only — never the address) and the work goes on.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-customer-privacy-customer-emails, US-personal-data-requests-admin-notify
 * @aidlc-adr customer-privacy_self-service-surfaces
 */
final class PrivacyNotices
{
    /**
     * @param Closure(PrivacyMailer): void $send
     * @param string|null                  $storeId Store of the request: links use its domain, not the
     *                                              domain of the page being served when the email goes out.
     * @return bool Whether the message was handed over without an error.
     */
    public static function send(Closure $send, ?string $storeId = null): bool
    {
        $previous = config('app.storeId');
        if ($storeId !== null && $storeId !== '') {
            config(['app.storeId' => $storeId]);
        }
        try {
            $send(app(PrivacyMailer::class));

            return true;
        } catch (\Throwable $e) {
            if (function_exists('gp247_report')) {
                gp247_report('Personal data request email not sent: ' . $e::class);
            }

            return false;
        } finally {
            config(['app.storeId' => $previous]);
        }
    }

    /**
     * @param object $request A row of personal_data_request.
     * @return string|null The address of an open request (encrypted at rest, removed when it closes).
     */
    public static function emailOf(object $request): ?string
    {
        if (empty($request->email_enc)) {
            return null;
        }
        try {
            $email = gp247_secret_decrypt((string) $request->email_enc);
        } catch (\Throwable $e) {
            return null;
        }

        return $email === '' ? null : $email;
    }
}
