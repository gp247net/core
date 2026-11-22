<?php

namespace GP247\Core\Tests\Unit\Compat;

use GP247\Core\Mail\MailQueueAdvisor;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for the mail-queue delivery advisor (no Laravel boot / no DB):
 * the scheduler-guard decision and the admin guidance state machine.
 *
 * @aidlc-unit installer-deploy
 * @aidlc-story US-DEP-mail-queue-runner
 * @aidlc-adr compat-foundation_mail-delivery-hardening
 */
class MailQueueAdvisorTest extends TestCase
{
    /**
     * The scheduled queue:work drain is registered only for a real queue connection
     * AND when the opt-out flag is enabled.
     */
    public function test_should_schedule_worker_requires_real_connection_and_flag(): void
    {
        $this->assertTrue(MailQueueAdvisor::shouldScheduleWorker('database', true));
        $this->assertTrue(MailQueueAdvisor::shouldScheduleWorker('redis', true));

        // sync connection → nothing to drain, never schedule.
        $this->assertFalse(MailQueueAdvisor::shouldScheduleWorker('sync', true));
        // flag off (own supervisor worker) → skip to avoid a redundant process.
        $this->assertFalse(MailQueueAdvisor::shouldScheduleWorker('database', false));
        $this->assertFalse(MailQueueAdvisor::shouldScheduleWorker('sync', false));
    }

    /**
     * Guidance state machine: queue off → direct; sync → queue_sync; real connection
     * → queue_auto (auto-scheduler) or queue_manual (own worker).
     */
    public function test_guide_state_machine(): void
    {
        $this->assertSame('direct', MailQueueAdvisor::guideState(false, 'database', true));
        $this->assertSame('direct', MailQueueAdvisor::guideState(false, 'sync', true));
        $this->assertSame('queue_sync', MailQueueAdvisor::guideState(true, 'sync', true));
        $this->assertSame('queue_auto', MailQueueAdvisor::guideState(true, 'database', true));
        $this->assertSame('queue_manual', MailQueueAdvisor::guideState(true, 'database', false));
        $this->assertSame('queue_manual', MailQueueAdvisor::guideState(true, 'redis', false));
    }
}
