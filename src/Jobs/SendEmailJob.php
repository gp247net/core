<?php

namespace GP247\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued mail delivery.
 *
 * WHY the safety limits: without $tries/$timeout a slow or hanging SMTP server
 * could hold a worker indefinitely and retry forever. Delivery lets exceptions
 * propagate (throwOnError=true) so a genuine failure lands in failed_jobs instead
 * of being swallowed as a false success.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-mail-delivery-hardening
 * @aidlc-adr compat-foundation_mail-delivery-hardening
 */
class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Max attempts before the job is marked failed. */
    public $tries = 3;

    /** @var int Seconds a single attempt may run before timing out. */
    public $timeout = 30;

    /** @var int Seconds to wait between retries. */
    public $backoff = 60;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public $view;
    public $dataView;
    public $emailConfig;
    public $attach;
    public function __construct($view, array $dataView = [], array $emailConfig = [], array $attach = [])
    {
        $this->view = $view;
        $this->dataView = $dataView;
        $this->emailConfig = $emailConfig;
        $this->attach = $attach;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        gp247_mail_process_send($this->view, $this->dataView, $this->emailConfig, $this->attach, true);
    }
}
