<?php

namespace GP247\Core\Tests\Feature\SystemCli;

use GP247\Core\Database\Seeders\DataDefaultSeeder;
use GP247\Core\Models\AdminUser;
use GP247\Core\Support\Password\InitialAdminPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use GP247\Core\Tests\TestCase;

/**
 * Installing from the command line no longer leaves an `admin/admin` account: the seeded
 * administrator gets the password passed with --admin-password or a random one shown once.
 *
 * WHY not Artisan::call('gp247:core-install'): the command migrates and re-creates the admin
 * tables; it must never run against the shared test database. The command only wires the
 * pieces tested here (validate before any step, assign after the seed, print once).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-003
 * @aidlc-adr admin-shell_password-policy-registry
 */
class CoreInstallAdminPasswordTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Put the seeded administrator back on the seed hash (what a fresh seed leaves).
     *
     * @return void
     */
    private function freshlySeededAdmin(): void
    {
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_user')
            ->where('id', DataDefaultSeeder::SEED_ADMIN_ID)
            ->update(['password' => (new DataDefaultSeeder)->adminPassword]);
    }

    public function test_a_password_passed_on_the_command_line_must_meet_the_administrator_default_policy(): void
    {
        $this->assertNotNull(InitialAdminPassword::problem('short'));
        $this->assertNotNull(InitialAdminPassword::problem(str_repeat('a', 65)));
        $this->assertNull(InitialAdminPassword::problem('Owner-Pass-2026'));
    }

    public function test_the_refusal_never_repeats_the_password_that_was_passed(): void
    {
        $this->assertStringNotContainsString('Sup3rSecretX', (string) InitialAdminPassword::problem('Sup3rSecretX'));
        $this->assertStringNotContainsString('tiny', (string) InitialAdminPassword::problem('tiny'));
    }

    public function test_a_generated_password_meets_the_administrator_default_policy(): void
    {
        $password = InitialAdminPassword::generate();

        $this->assertSame(20, strlen($password));
        $this->assertNull(InitialAdminPassword::problem($password));
        $this->assertNotSame($password, InitialAdminPassword::generate(), 'every install gets its own password');
    }

    public function test_the_seeded_administrator_takes_the_new_password_and_admin_no_longer_signs_in(): void
    {
        $this->freshlySeededAdmin();

        $this->assertTrue(InitialAdminPassword::assign('Owner-Pass-2026'));

        $admin = AdminUser::find(DataDefaultSeeder::SEED_ADMIN_ID);
        $this->assertTrue(Hash::check('Owner-Pass-2026', $admin->password));
        $this->assertFalse(Hash::check('admin', $admin->password));
    }

    public function test_an_administrator_who_already_changed_the_password_is_not_overwritten(): void
    {
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_user')
            ->where('id', DataDefaultSeeder::SEED_ADMIN_ID)
            ->update(['password' => Hash::make('Kept-Owner-Pass')]);

        $this->assertFalse(InitialAdminPassword::assign('Other-Pass-2026'));

        $this->assertTrue(Hash::check('Kept-Owner-Pass', AdminUser::find(DataDefaultSeeder::SEED_ADMIN_ID)->password));
    }

    public function test_the_install_command_no_longer_announces_admin_admin_and_accepts_admin_password(): void
    {
        $source = file_get_contents(base_path('vendor/gp247/core/src/Commands/Install.php'));
        $this->assertStringNotContainsString('admin/admin', $source);
        $this->assertStringContainsString('{--admin-password=', $source);
        $this->assertStringContainsString('{--admin-password=', file_get_contents(base_path('vendor/gp247/core/src/Commands/InstallAll.php')));
    }
}
