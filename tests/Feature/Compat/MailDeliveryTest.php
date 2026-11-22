<?php

namespace GP247\Core\Tests\Feature\Compat;

use GP247\Core\Jobs\SendEmailJob;
use GP247\Core\Mail\SendMail;
use Illuminate\Support\Facades\Mail;
use GP247\Core\Tests\TestCase;

/**
 * Mail delivery hardening — recipient guard, error handling per path, return
 * contract and queued-job safety limits.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-mail-delivery-hardening
 * @aidlc-adr compat-foundation_mail-delivery-hardening
 */
class MailDeliveryTest extends TestCase
{
    /**
     * An empty recipient must be skipped (not handed to the Mailer) and reported,
     * returning false — RISK-OPS-mail-silent-failure / recipient guard.
     */
    public function test_process_send_skips_empty_recipient(): void
    {
        Mail::fake();

        $result = gp247_mail_process_send('gp247-admin::email.forgot_password', [], [], []);

        $this->assertFalse($result, 'Empty recipient must return false');
        Mail::assertNothingSent();
    }

    /**
     * With a recipient present the mailable is handed to the Mailer and the call
     * returns true.
     */
    public function test_process_send_dispatches_to_mailer_with_recipient(): void
    {
        Mail::fake();

        $result = gp247_mail_process_send(
            'gp247-admin::email.forgot_password',
            ['title' => 'x'],
            ['to' => 'customer@example.com', 'subject' => 'Hi'],
            []
        );

        $this->assertTrue($result, 'A valid send must return true');
        Mail::assertSent(SendMail::class);
    }

    /**
     * Sync path swallows a Mailer error (so business flows like checkout are not
     * broken) and returns false — the error is still logged by gp247_report.
     */
    public function test_sync_path_swallows_mailer_error(): void
    {
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp down'));

        $result = gp247_mail_process_send('view', [], ['to' => 'a@b.com'], [], false);

        $this->assertFalse($result, 'Sync path must swallow and return false');
    }

    /**
     * Queue path re-throws so the job fails and can retry / land in failed_jobs
     * instead of a false "success" — NFR-MAINT-mail-observability.
     */
    public function test_queue_path_rethrows_mailer_error(): void
    {
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->expectException(\RuntimeException::class);

        gp247_mail_process_send('view', [], ['to' => 'a@b.com'], [], true);
    }

    /**
     * SendEmailJob must carry safety limits (tries/timeout/backoff) so a hanging
     * SMTP server cannot pin a worker or retry forever.
     */
    public function test_send_email_job_has_safety_limits(): void
    {
        $job = new SendEmailJob('view', [], ['to' => 'a@b.com'], []);

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame(60, $job->backoff);
    }
}
