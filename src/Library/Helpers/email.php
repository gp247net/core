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
 * Configured origin for links sent by e-mail, for the store serving the request:
 * - a store with its own domain ⇒ that domain;
 * - the root store ⇒ APP_URL, unless APP_URL still points at the local machine
 *   (localhost / 127.0.0.1 / ::1 — the .env.example default, which `gp247:install`
 *   does not change), then the root store's domain saved at install time.
 * The scheme is APP_URL's; with a local APP_URL it is the request's (X-Forwarded-Proto
 * is only trusted from a declared proxy). Without a usable domain: APP_URL.
 *
 * @return array{0: string, 1: string} [origin without trailing slash, scheme]
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-mail-link-configured-domain
 * @aidlc-adr compat-foundation_forwarded-header-trust
 */
if (!function_exists('gp247_mail_link_origin') && !in_array('gp247_mail_link_origin', config('gp247_functions_except', []))) {
    function gp247_mail_link_origin(): array
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $appScheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $host = trim(strtolower((string) parse_url($appUrl, PHP_URL_HOST)), '[]');
        $localAppUrl = in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true);
        $scheme = $localAppUrl ? (request()->getScheme() ?: $appScheme) : $appScheme;

        $root = (string) GP247_STORE_ID_ROOT;
        $storeId = (string) config('app.storeId', $root);
        $lookup = ($storeId !== '' && $storeId !== $root) ? $storeId : ($localAppUrl ? $root : null);
        if ($lookup !== null) {
            try {
                $domain = (string) (\GP247\Core\Models\AdminStore::getDomainStore()[$lookup] ?? '');
            } catch (\Throwable $e) {
                // WHY: a store table that cannot be read must not stop the mail; fall back to APP_URL.
                $domain = '';
            }
            $domain = gp247_store_process_domain($domain);
            if ($domain !== '') {
                return [$scheme . '://' . $domain, $scheme];
            }
        }

        return [$appUrl, $appScheme];
    }
}

/**
 * Build a link that leaves the site in an e-mail (password reset, e-mail
 * verification…) against the configured origin of the store serving the request
 * (gp247_mail_link_origin). The request's Host is never used, so a forged Host
 * cannot send a secret-bearing link to another domain.
 *
 * Only the link is affected: the URL generator is swapped for a copy while $build
 * runs and put back afterwards, so the page being served keeps its own root.
 *
 * @param callable(): string $build Builds the URL (route(), URL::temporarySignedRoute()…).
 * @return string The URL $build returned, rooted at the configured origin.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-mail-link-configured-domain
 * @aidlc-adr compat-foundation_forwarded-header-trust
 */
if (!function_exists('gp247_mail_link_url') && !in_array('gp247_mail_link_url', config('gp247_functions_except', []))) {
    function gp247_mail_link_url(callable $build): string
    {
        [$origin, $scheme] = gp247_mail_link_origin();

        $original = app('url');
        $pinned = clone $original;
        // WHY: a clone shares the original's RouteUrlGenerator, which formats roots through
        // the ORIGINAL generator; setRequest() drops it (keeping its default parameters) so
        // route() on the clone honours the forced root below.
        $pinned->setRequest($original->getRequest());
        $pinned->forceRootUrl($origin);
        $pinned->forceScheme($scheme);

        app()->instance('url', $pinned);
        \Illuminate\Support\Facades\URL::clearResolvedInstance('url');
        try {
            return (string) $build();
        } finally {
            app()->instance('url', $original);
            \Illuminate\Support\Facades\URL::clearResolvedInstance('url');
        }
    }
}

/**
 * Send email reset password
 */
if (!function_exists('gp247_mail_admin_send_reset_notification') && !in_array('gp247_mail_admin_send_reset_notification', config('gp247_functions_except', []))) {
    function gp247_mail_admin_send_reset_notification(string $token, string $emailReset)
    {
        $url = gp247_mail_link_url(fn () => gp247_route_admin('admin.password_reset', ['token' => $token]));
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
