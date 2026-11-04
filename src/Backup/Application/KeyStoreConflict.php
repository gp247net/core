<?php

namespace GP247\Core\Backup\Application;

use RuntimeException;

/**
 * The key ring changed between reading it and writing it (another set-up / rotation ran).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-key-setup
 * @aidlc-adr backup-engine_envelope-encryption
 */
final class KeyStoreConflict extends RuntimeException
{
}
