<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\Database\Seeders\DataDefaultSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use GP247\Core\Tests\TestCase;

/**
 * `php artisan gp247:update` re-runs the core seeder after the upgrade migrations on
 * every update, so a seeded row must never be added twice: the dashboard keeps one
 * "System alerts" block however many times the site is updated, and a site that got
 * two blocks from core 3.10.0 is cleaned up by the next update (oldest row kept).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 * @aidlc-adr admin-shell_system-alert-registry
 */
class AdminAlertBlockSeedTest extends TestCase
{
    use DatabaseTransactions;

    private const VIEW = 'gp247-admin::component.alerts';

    /**
     * Core tables the default seeder writes to.
     *
     * @var array<int, string>
     */
    private const SEEDED_TABLES = [
        'admin_menu', 'admin_permission', 'admin_role', 'admin_role_permission', 'admin_role_user',
        'admin_user', 'admin_config', 'admin_store', 'admin_store_description', 'admin_language', 'admin_home',
    ];

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function home()
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_home');
    }

    /**
     * @return void
     */
    private function runCoreSeeder(): void
    {
        (new DataDefaultSeeder())->run();
    }

    /**
     * @return object The upgrade migration that removes duplicate alert blocks.
     */
    private function dedupeMigration(): object
    {
        return require base_path('vendor/gp247/core/src/Database/Migrations/upgrade/2026_11_04_180424_dedupe_admin_alert_block.php');
    }

    public function test_running_the_core_seeder_again_keeps_a_single_alerts_block(): void
    {
        $this->runCoreSeeder();
        $this->assertSame(1, $this->home()->where('view', self::VIEW)->count(), 'one block after the first seed');

        $this->runCoreSeeder();
        $this->runCoreSeeder();

        $this->assertSame(1, $this->home()->where('view', self::VIEW)->count(), 'gp247:update re-seeds: still one block');
    }

    public function test_running_the_core_seeder_twice_adds_no_rows_anywhere(): void
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $this->runCoreSeeder();
        $before = [];
        foreach (self::SEEDED_TABLES as $table) {
            if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . $table)) {
                $before[$table] = $db->table(GP247_DB_PREFIX . $table)->count();
            }
        }

        $this->runCoreSeeder();

        foreach ($before as $table => $count) {
            $this->assertSame($count, $db->table(GP247_DB_PREFIX . $table)->count(), $table . ' gained rows on the second seed');
        }
    }

    public function test_the_upgrade_removes_duplicate_alert_blocks_and_keeps_the_oldest(): void
    {
        $this->home()->where('view', self::VIEW)->delete();
        $ids = [];
        foreach ([1000, 1000, 1001] as $sort) {
            $ids[] = $this->home()->insertGetId(['view' => self::VIEW, 'size' => 12, 'sort' => $sort, 'status' => 1]);
        }
        $others = $this->home()->where('view', '<>', self::VIEW)->count();

        $migration = $this->dedupeMigration();
        $migration->up();
        $migration->up();

        $kept = $this->home()->where('view', self::VIEW)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([min($ids)], $kept);
        $this->assertSame($others, $this->home()->where('view', '<>', self::VIEW)->count(), 'other blocks untouched');
    }
}
