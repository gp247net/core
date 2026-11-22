<?php

namespace GP247\Core\Tests\Feature\AdminShell\Support;

use GP247\Core\Testing\InteractsWithAdminUser as SharedInteractsWithAdminUser;

/**
 * Shared helpers for admin-shell Livewire feature tests (fake admin user, denial asserts).
 *
 * WHY an alias: the helper moved to src/Testing so that the test suites of other packages
 * can use it; tests that use this name keep working with one implementation.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-001
 */
trait InteractsWithAdminUser
{
    use SharedInteractsWithAdminUser;
}
