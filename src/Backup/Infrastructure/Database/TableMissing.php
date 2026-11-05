<?php

namespace GP247\Core\Backup\Infrastructure\Database;

use RuntimeException;

/**
 * A table planned for the backup no longer exists (an extension was removed meanwhile). The
 * backup goes on without it and records a warning; any other database error still fails.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class TableMissing extends RuntimeException
{
}
