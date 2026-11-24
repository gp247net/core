<?php

namespace GP247\Core\Backup\Domain;

/**
 * Proof that an admin typed their sign-in password again (and the second-step code when the
 * site has one) a moment ago, in this browser session. The logic lives in the shared step-up
 * (ADR personal-data-registry_shared-step-up); this name stays for the backup feature.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-step-up-auth
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
final class StepUpMark extends \GP247\Core\Support\StepUp\StepUpMark
{
}
