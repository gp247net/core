<?php

namespace GP247\Core\Support\PreChange;

/**
 * One registrant's answer to a {@see PreChange}: go on (optionally with a notice), or stop with a reason.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-pre-change-hook
 * @aidlc-adr system-cli_pre-change-registry
 */
final class PreChangeResult
{
    /**
     * @param bool        $proceed    Whether the change may run.
     * @param string|null $reason     Why it must stop (shown to the person running the change).
     * @param string|null $notice     Reminder shown while still proceeding (e.g. "backup not set up").
     * @param string|null $pendingJob Id of work still running that the person can wait for.
     */
    private function __construct(
        public readonly bool $proceed,
        public readonly ?string $reason = null,
        public readonly ?string $notice = null,
        public readonly ?string $pendingJob = null,
    ) {
    }

    /**
     * Let the change run.
     *
     * @param string|null $notice Optional reminder for the person running the change.
     * @return self
     */
    public static function proceed(?string $notice = null): self
    {
        return new self(true, null, $notice);
    }

    /**
     * Ask the caller to stop before changing anything.
     *
     * @param string      $reason     Human-readable reason.
     * @param string|null $pendingJob Work still running, if the stop is temporary.
     * @return self
     */
    public static function stop(string $reason, ?string $pendingJob = null): self
    {
        return new self(false, $reason, null, $pendingJob);
    }
}
