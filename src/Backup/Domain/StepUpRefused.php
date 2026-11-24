<?php

namespace GP247\Core\Backup\Domain;

/**
 * The admin could not confirm who they are again. Reason codes are stable (screen strings);
 * the constants are inherited from the shared step-up (ADR personal-data-registry_shared-step-up).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-step-up-auth
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
final class StepUpRefused extends \GP247\Core\Support\StepUp\StepUpRefused
{
}
