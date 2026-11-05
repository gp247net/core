<?php

namespace GP247\Core\Backup\Application;

use RuntimeException;

/**
 * The slice no longer owns its job (lease expired and taken over, or the job was cancelled):
 * it stops at once without writing anything more.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class LeaseLost extends RuntimeException
{
}
