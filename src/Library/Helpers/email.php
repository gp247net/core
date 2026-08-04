<?php
use GP247\Core\Mail\SendMail;
use GP247\Core\Jobs\SendEmailJob;
use Illuminate\Support\Facades\Mail;

/**
 * Send a mail through the configured pipeline.
 *
 * Honours two store config toggles: `email_action_mode` (master on/off) and
 * `email_action_queue`. When queueing is on, delivery is deferred to
 * SendEmailJob — that requires a running `queue:work` process (NOT `schedule:run`)
 * unless QUEUE_CONNECTION is `sync`.
 *
 * @param   string  $view         Path to the mail view.
 * @param   array   $dataView     Data passed to the view.
 * @param   array   $emailConfig  to, cc, bcc, replyTo, subject...
 * @param   array   $attach       Attachments (fileAttach / fileAttachData / attachFromStorage).
 *
 * @return  bool    True when accepted for delivery (sent synchronously or queued);
 *                  false when mail is disabled or delivery was skipped.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-mail-delivery-hardening
 * @aidlc-adr compat-foundation_mail-delivery-hardening
 */
if (!function_exists('gp247_mail_send') && !in_array('gp247_mail_send', config('gp247_functions_except', []))) {
    function gp247_mail_send($view, array $dataView = [], array $emailConfig = [], array $attach = []): bool
    {
        //Check email action mode is enable
        if (empty(gp247_config('email_action_mode'))) {
            return false;
        }

        // Check email action queue is enable
        if (!empty(gp247_config('email_action_queue'))) {
            dispatch(new SendEmailJob($view, $dataView, $emailConfig, $attach));
            return true;
        }

        return gp247_mail_process_send($view, $dataView, $emailConfig, $attach);
    }
}
/**
 * Actually hand the mail to the Mailer.
 *
 * @param   string  $view          Path to the mail view.
 * @param   array   $dataView      Data passed to the view.
 * @param   array   $emailConfig   to, cc, bcc, replyTo, subject...
 * @param   array   $attach        Attachments.
 * @param   bool    $throwOnError  When true (queue path), rethrow after reporting so the
 *                                 job can retry / land in failed_jobs instead of a false
 *                                 "success". When false (sync path, e.g. checkout) the error
 *                                 is swallowed so the business flow is not broken — it is
 *                                 still logged (gp247_report always writes the daily channel).
 *
 * @return  bool    True when handed to the Mailer; false when skipped (empty recipient) or
 *                  when a swallowed error occurred on the sync path.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-mail-delivery-hardening
 * @aidlc-adr compat-foundation_mail-delivery-hardening
 */
if (!function_exists('gp247_mail_process_send') && !in_array('gp247_mail_process_send', config('gp247_functions_except', []))) {
    function gp247_mail_process_send($view, array $dataView = [], array $emailConfig = [], array $attach = [], bool $throwOnError = false): bool
    {
        // Recipient guard (centralised): skip instead of letting the Mailer throw on an
        // empty `to`. WHY: some callers pass a store-config email that may be blank
        // (e.g. order-to-admin), which previously crashed inside a swallowed try/catch.
        if (empty($emailConfig['to'])) {
            gp247_report("Sendmail skipped — empty recipient. View: " . $view);
            return false;
        }

        try {
            Mail::send(new SendMail($view, $dataView, $emailConfig, $attach));
            return true;
        } catch (\Throwable $e) {
            gp247_report("Sendmail view: " . $view . PHP_EOL . $e->getMessage());
            // On the queue path let it fail so Laravel retries / records failed_jobs.
            if ($throwOnError) {
                throw $e;
            }
            return false;
        }
    }
}


/**
 * Send email reset password
 */
if (!function_exists('gp247_mail_admin_send_reset_notification') && !in_array('gp247_mail_admin_send_reset_notification', config('gp247_functions_except', []))) {
    function gp247_mail_admin_send_reset_notification(string $token, string $emailReset)
    {
        $url = gp247_route_admin('admin.password_reset', ['token' => $token]);
        $dataView = [
            'title' => gp247_language_render('email.forgot_password.title'),
            'reason_sendmail' => gp247_language_render('email.forgot_password.reason_sendmail'),
            'note_sendmail' => gp247_language_render('email.forgot_password.note_sendmail', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]),
            'note_access_link' => gp247_language_render('email.forgot_password.note_access_link', ['reset_button' => gp247_language_render('email.forgot_password.reset_button'), 'url' => $url]),
            'reset_link' => $url,
            'reset_button' => gp247_language_render('email.forgot_password.reset_button'),
        ];

        $config = [
            'to' => $emailReset,
            'subject' => gp247_language_render('email.forgot_password.reset_button'),
        ];

        gp247_mail_send('gp247-admin::email.forgot_password', $dataView, $config, $dataAtt = []);
    }
}
