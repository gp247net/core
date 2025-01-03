<?php
use GP247\Core\Mail\SendMail;
use GP247\Core\Jobs\SendEmailJob;
use Illuminate\Support\Facades\Mail;

/**
 * Function send mail
 * Mail queue to run need setting crontab for php artisan schedule:run
 *
 * @param   [string]  $view            Path to view
 * @param   array     $dataView        Content send to view
 * @param   array     $emailConfig     to, cc, bbc, subject..
 * @param   array     $attach      Attach file
 *
 * @return  mixed
 */
if (!function_exists('gp247_mail_send') && !in_array('gp247_mail_send', config('gp247_functions_except', []))) {
    function gp247_mail_send($view, array $dataView = [], array $emailConfig = [], array $attach = [])
    {
        //Check email action mode is enable
        if (!empty(gp247_config('email_action_mode'))) {
            // Check email action queue is enable
            if (!empty(gp247_config('email_action_queue'))) {
                dispatch(new SendEmailJob($view, $dataView, $emailConfig, $attach));
            } else {
                gp247_mail_process_send($view, $dataView, $emailConfig, $attach);
            }
        } else {
            return false;
        }
    }
}
/**
 * Process send mail
 *
 * @param   [type]  $view         [$view description]
 * @param   array   $dataView     [$dataView description]
 * @param   array   $emailConfig  [$emailConfig description]
 * @param   array   $attach       [$attach description]
 *
 * @return  [][][]                [return description]
 */
if (!function_exists('gp247_mail_process_send') && !in_array('gp247_mail_process_send', config('gp247_functions_except', []))) {
    function gp247_mail_process_send($view, array $dataView = [], array $emailConfig = [], array $attach = [])
    {
        try {
            Mail::send(new SendMail($view, $dataView, $emailConfig, $attach));
        } catch (\Throwable $e) {
            gp247_report("Sendmail view: " . $view . PHP_EOL . $e->getMessage());
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

        gp247_mail_send('gp247-core::email.forgot_password', $dataView, $config, $dataAtt = []);
    }
}
