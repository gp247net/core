<?php

namespace GP247\Core\Tests\Unit\Compat;

use GP247\Core\Database\Seeders\DataDefaultSeeder;
use PHPUnit\Framework\TestCase;

/**
 * On a fresh install the default permissions, roles and role assignments are inserted with
 * fixed ids (permission 1–4 for the Content Manager role). An installer that adds a
 * permission of its own with an automatic id must run after those rows: run before, it takes
 * id 1, the default "Dashboard" permission is skipped as a duplicate and the Content Manager
 * role receives the installer's permission instead.
 *
 * WHY a source check: a test database that already holds the default rows never shows the
 * collision (DELETE does not reset the auto-increment); the clean-install CI run does.
 *
 * @aidlc-unit installer-deploy
 * @aidlc-story US-personal-data-requests-install-lifecycle
 */
class DefaultSeederPermissionOrderTest extends TestCase
{
    public function test_installers_that_add_permissions_run_after_the_default_permissions_are_inserted(): void
    {
        $method = new \ReflectionMethod(DataDefaultSeeder::class, 'run');
        $lines = file((string) $method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $anchor = strpos($body, '->insertOrIgnore($dataAdminPermission)');
        $this->assertNotFalse($anchor, 'the default permissions are inserted in run()');

        preg_match_all('/\\\\?((?:[A-Z][A-Za-z0-9_]*\\\\)+[A-Z][A-Za-z0-9_]*)::ensure\(/', $body, $calls, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($calls[1], 'run() calls installers');

        $checked = 0;
        foreach ($calls[1] as [$class, $offset]) {
            $class = ltrim($class, '\\');
            $this->assertTrue(class_exists($class), $class);
            $source = (string) file_get_contents((string) (new \ReflectionClass($class))->getFileName());
            if (!str_contains($source, "'admin_permission'")) {
                continue;
            }
            $checked++;
            $this->assertGreaterThan($anchor, $offset, $class . '::ensure() adds a permission, so it must run after the default permissions are inserted');
        }
        $this->assertGreaterThan(0, $checked, 'at least one installer adds a permission (personal data, backup)');
    }
}
