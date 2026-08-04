<?php

namespace GP247\Core\Mail;

/**
 * Pure decision logic for queued-mail delivery: whether to auto-register the
 * scheduled queue:work drain, and which guidance state to show the admin.
 *
 * Kept free of Laravel/DB dependencies so both the service provider (scheduler
 * registration) and the settings screen (guidance panel) share one tested source
 * of truth.
 *
 * @aidlc-unit installer-deploy
 * @aidlc-story US-DEP-mail-queue-runner
 * @aidlc-adr compat-foundation_mail-delivery-hardening
 */
final class MailQueueAdvisor
{
    /**
     * Whether GP247 should auto-register the scheduled `queue:work` drain.
     *
     * @param string $connection The active QUEUE_CONNECTION (config('queue.default')).
     * @param bool   $flagEnabled The GP247_SCHEDULE_QUEUE_WORK opt-out flag.
     * @return bool True only for a real (non-sync) connection with the flag enabled.
     */
    public static function shouldScheduleWorker(string $connection, bool $flagEnabled): bool
    {
        // WHY not sync: 'sync' has no queue to drain. WHY the flag: a site running
        // its own persistent worker opts out to avoid a redundant per-minute process.
        return $connection !== 'sync' && $flagEnabled;
    }

    /**
     * Resolve the admin guidance state for the current delivery configuration.
     *
     * @param bool   $queueOn Whether email_action_queue is enabled.
     * @param string $connection The active QUEUE_CONNECTION.
     * @param bool   $autoScheduler Whether the auto-scheduler flag is enabled.
     * @return string One of: 'direct', 'queue_sync', 'queue_auto', 'queue_manual'.
     */
    public static function guideState(bool $queueOn, string $connection, bool $autoScheduler): string
    {
        if (!$queueOn) {
            return 'direct';
        }
        if ($connection === 'sync') {
            return 'queue_sync';
        }

        return $autoScheduler ? 'queue_auto' : 'queue_manual';
    }
}
