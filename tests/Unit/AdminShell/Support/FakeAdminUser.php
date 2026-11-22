<?php

namespace GP247\Core\Tests\Unit\AdminShell\Support;

/*
 * In-memory admin user for admin-shell tests.
 *
 * WHY an alias: the class moved to src/Testing so that the test suites of other packages
 * can use it; tests that use this name get the very same class (instanceof works both ways).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-001
 */
class_alias(\GP247\Core\Testing\FakeAdminUser::class, __NAMESPACE__ . '\\FakeAdminUser');
