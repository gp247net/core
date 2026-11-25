<?php

namespace GP247\Core\Tests\Feature\PersonalData\Retention;

use Carbon\CarbonImmutable;
use GP247\Core\Middleware\PermissionMiddleware;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Application\Retention\RetentionPolicies;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\PersonalData\Infrastructure\Requests\PersonalDataCheck;
use GP247\Core\PersonalData\Infrastructure\Requests\PersonalDataInstaller;
use GP247\Core\PersonalData\Livewire\RetentionScreen;
use GP247\Core\Support\PreChange\PreChange;
use GP247\Core\Support\PreChange\PreChangeResult;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Core\Tests\Feature\PersonalData\Requests\Support\UsesRequestScreen;
use GP247\Core\Tests\Feature\PersonalData\Retention\Support\UsesRetentionFixtures;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;

/**
 * The "Data retention" screen is for Administrators only: an item is turned on or off and its days
 * changed (kept within range); turning on an item that removes business data shows how many
 * records it concerns, asks for the password again and lets the backup save the data first; "run
 * now" before the first allowed clean asks to confirm. The command cleans for sites with cron, the
 * lazy tick without, and the system check warns before a first clean.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-policies, US-personal-data-retention-lazy-run, US-personal-data-retention-order-anonymise, US-personal-data-retention-admin-log
 * @aidlc-adr personal-data-retention_retention-registry, personal-data-retention_sweep-runner, personal-data-retention_record-anonymise
 */
#[Group('personal-data-retention')]
class RetentionScreenTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use UsesPersonalDataFixtures;
    use UsesRequestScreen;
    use UsesRetentionFixtures;

    private CarbonImmutable $now;
    /** @var list<PreChange> */
    private array $preChanges = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPersonalData();
        $this->setUpRetention();
        PersonalDataInstaller::ensure(DB::connection(GP247_DB_CONNECTION));
        Cache::flush();
        $this->now = CarbonImmutable::now()->startOfSecond();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->tearDownPersonalData();
        parent::tearDown();
    }

    public function test_an_administrator_changes_an_items_days_within_its_range_and_turns_another_off(): void
    {
        $this->signInAdministrator();

        Livewire::test(RetentionScreen::class)
            ->assertSee('test.wishlist')
            ->set('days.test__wishlist', '5')->call('save', 'test.wishlist')
            ->set('enabled.test__admin_log', false)->call('save', 'test.admin_log');

        $policies = app(RetentionPolicies::class);
        $this->assertSame(30, $policies->days(PersonalDataRegistry::retentionItem('test.wishlist')), 'below the minimum ⇒ the minimum');
        $this->assertFalse($policies->enabled(PersonalDataRegistry::retentionItem('test.admin_log')));
    }

    public function test_turning_on_old_order_anonymisation_shows_the_count_asks_the_password_and_lets_the_backup_save_first(): void
    {
        $this->captureBackup(PreChangeResult::proceed('saved first'));
        $this->insertRow('pdt_rt_order', ['first_name' => 'Nguyen Van An', 'status' => 5, 'created_at' => $this->now->subYears(11)]);
        $this->insertRow('pdt_rt_order', ['first_name' => 'Tran Thi Binh', 'status' => 5, 'created_at' => $this->now->subYears(1)]);
        $user = $this->signInAdministrator();
        RateLimiter::clear('personal-data-step-up:' . $user->id);
        $item = PersonalDataRegistry::retentionItem('test.orders');

        $screen = Livewire::test(RetentionScreen::class)
            ->set('enabled.test__orders', true)->call('save', 'test.orders')
            ->assertSet('confirmKey', 'test.orders')
            ->assertSet('confirmCount', 1);
        $this->assertFalse(app(RetentionPolicies::class)->enabled($item), 'nothing is saved before the confirmation');

        $screen->call('confirmRisky')->assertSet('stepUpOpen', true);
        $this->assertFalse(app(RetentionPolicies::class)->enabled($item));
        $screen->set('stepUpPassword', self::ADMIN_PASSWORD)->call('confirmStepUp');

        $this->assertTrue(app(RetentionPolicies::class)->enabled($item));
        $this->assertCount(1, $this->preChanges);
        $this->assertSame(PreChange::PERSONAL_DATA_RETENTION, $this->preChanges[0]->operation);
        $this->assertSame(['orders'], $this->preChanges[0]->scope['groups']);
        $this->assertNotNull($this->stateOf('test.orders')->enabled_at);
    }

    public function test_what_is_saved_is_what_the_preview_counted_even_if_the_page_changes_afterwards(): void
    {
        $this->captureBackup(PreChangeResult::proceed());
        $user = $this->signInAdministrator();
        RateLimiter::clear('personal-data-step-up:' . $user->id);

        Livewire::test(RetentionScreen::class)
            ->set('enabled.test__orders', true)->set('days.test__orders', '5000')->call('save', 'test.orders')
            ->assertSet('confirmDays', 5000)
            ->set('days.test__orders', '365')
            ->call('confirmRisky')->set('stepUpPassword', self::ADMIN_PASSWORD)->call('confirmStepUp');

        $this->assertSame(5000, app(RetentionPolicies::class)->days(PersonalDataRegistry::retentionItem('test.orders')));
    }

    public function test_a_backup_that_cannot_save_first_stops_the_change(): void
    {
        $this->captureBackup(PreChangeResult::stop('backup busy'));
        $user = $this->signInAdministrator();
        RateLimiter::clear('personal-data-step-up:' . $user->id);

        Livewire::test(RetentionScreen::class)
            ->set('enabled.test__orders', true)->call('save', 'test.orders')
            ->call('confirmRisky')->set('stepUpPassword', self::ADMIN_PASSWORD)->call('confirmStepUp')
            ->assertSet('preChangeMessages', ['backup busy']);

        $this->assertFalse(app(RetentionPolicies::class)->enabled(PersonalDataRegistry::retentionItem('test.orders')));
    }

    public function test_run_now_before_the_first_allowed_clean_asks_to_confirm_and_the_password(): void
    {
        $this->insertRow('pdt_rt_log', ['content' => 'old', 'created_at' => $this->now->subYears(2)]);
        $user = $this->signInAdministrator();
        RateLimiter::clear('personal-data-step-up:' . $user->id);

        $screen = Livewire::test(RetentionScreen::class)->call('runNow', 'test.admin_log')->assertSet('earlyKey', 'test.admin_log');
        $this->assertSame(1, $this->rows('pdt_rt_log')->count());

        $screen->call('runEarly')->assertSet('stepUpOpen', true)->set('stepUpPassword', self::ADMIN_PASSWORD)->call('confirmStepUp');

        $this->assertSame(0, $this->rows('pdt_rt_log')->count());
    }

    public function test_only_an_administrator_opens_or_changes_retention_even_with_view_all_or_a_role(): void
    {
        $this->asUser(viewAll: true);
        $this->assertDenied(fn () => Livewire::test(RetentionScreen::class));

        $this->asUser(slugs: ['admin_personal_data_requests']);
        $this->assertDenied(fn () => Livewire::test(RetentionScreen::class));

        $this->assertContains(GP247_ADMIN_PREFIX . '/personal_data_retention', (new PermissionMiddleware())->administratorOnly());
        $this->assertTrue(Route::has('admin.personal_data_retention'));
    }

    public function test_installing_twice_gives_one_menu_item_and_the_defaults_of_every_item_keeping_the_owners_choice(): void
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        PersonalDataInstaller::ensure($db);
        $key = PersonalDataRegistry::retentionItem('test.wishlist')->daysKey();
        $this->rows('admin_config')->where('key', $key)->update(['value' => '90']);

        PersonalDataInstaller::ensure($db);

        $this->assertSame(1, $this->rows('admin_menu')->where('uri', PersonalDataInstaller::RETENTION_MENU_URI)->count());
        $this->assertSame('90', $this->rows('admin_config')->where('key', $key)->value('value'));
        $this->assertSame('0', $this->rows('admin_config')->where('key', PersonalDataRegistry::retentionItem('test.orders')->enabledKey())->value('value'), 'off by default');
    }

    public function test_the_command_counts_on_a_dry_run_without_changing_anything_and_then_cleans_items_that_are_due(): void
    {
        $this->allowCleaning($this->now);
        $this->insertRow('pdt_rt_log', ['content' => 'old', 'created_at' => $this->now->subYears(2)]);

        $this->assertSame(0, Artisan::call('gp247:personal-data-retention', ['--dry-run' => true, '--item' => 'test.admin_log', '--json' => true]));
        $this->assertStringContainsString('"rows":1', Artisan::output());
        $this->assertSame(1, $this->rows('pdt_rt_log')->count());

        $this->assertSame(0, Artisan::call('gp247:personal-data-retention', ['--json' => true]));
        $output = Artisan::output();
        $this->assertSame(0, $this->rows('pdt_rt_log')->count());
        $this->assertStringContainsString('"item":"test.orders","status":"off"', $output);
    }

    public function test_the_lazy_tick_cleans_a_slice_and_the_system_check_warns_before_a_first_clean(): void
    {
        RequestScheduler::forgetReady();
        $this->insertRow('pdt_rt_log', ['content' => 'old', 'created_at' => $this->now->subYears(2)]);
        app(\GP247\Core\PersonalData\Application\Retention\RetentionSweeper::class)->states($this->now);
        $this->rows(PersonalDataSchema::RETENTION)->where('item_key', 'test.admin_log')->update(['first_allowed_at' => $this->now->addDays(3)]);

        $alert = (new PersonalDataCheck())->evaluate();
        $this->assertSame('warning', $alert->level);
        $this->assertStringContainsString('1 row(s) cleaned by a first retention run', $alert->detail);

        $this->allowCleaning($this->now);
        $this->rows(PersonalDataSchema::RETENTION)->where('item_key', '<>', 'test.admin_log')->update(['next_run_at' => $this->now->addHour()]);
        app(RequestScheduler::class)->tick(true);

        $this->assertSame(0, $this->rows('pdt_rt_log')->count());
    }

    /**
     * @param PreChangeResult $answer What the backup answers.
     * @return void
     */
    private function captureBackup(PreChangeResult $answer): void
    {
        config(['gp247-config.core.pre_change' => [['key' => 'backup', 'callback' => function (PreChange $change) use ($answer) {
            $this->preChanges[] = $change;

            return $answer;
        }]]]);
    }
}
