<?php

namespace GP247\Core\Tests\Feature\PersonalData\Retention;

use Carbon\CarbonImmutable;
use GP247\Core\PersonalData\Application\Erasure\ErasureJobRunner;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Retention\RetentionPolicies;
use GP247\Core\PersonalData\Application\Retention\RetentionSweeper;
use GP247\Core\PersonalData\Domain\ColumnRule;
use GP247\Core\PersonalData\Domain\Retention\RetentionItem;
use GP247\Core\PersonalData\Infrastructure\JobRepository;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\Tests\Feature\PersonalData\Retention\Support\UsesRetentionFixtures;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Data past its retention period is cleaned without cron, a small slice at a time, by the
 * rules declared for it: old rows deleted, old closed orders anonymised through the erasure
 * engine with their money kept, nothing cleaned in the 30 days after an update, each item at
 * most once an hour, an error kept on its item.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-lazy-run, US-personal-data-retention-admin-log, US-personal-data-retention-order-anonymise, US-personal-data-retention-policies
 * @aidlc-adr personal-data-retention_sweep-runner, personal-data-retention_record-anonymise, personal-data-retention_retention-registry
 */
#[Group('personal-data-retention')]
class RetentionSweepTest extends TestCase
{
    use DatabaseTransactions;
    use UsesPersonalDataFixtures;
    use UsesRetentionFixtures;

    private CarbonImmutable $now;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPersonalData();
        $this->setUpRetention();
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

    public function test_old_wishlist_rows_are_deleted_and_recent_ones_and_other_carts_stay(): void
    {
        $this->allowCleaning($this->now);
        $this->insertRow('pdt_rt_cart', ['identifier' => 'c-1', 'instance' => 'wishlist', 'updated_at' => $this->now->subDays(200)]);
        $this->insertRow('pdt_rt_cart', ['identifier' => 'c-2', 'instance' => 'wishlist', 'updated_at' => $this->now->subDays(10)]);
        $this->insertRow('pdt_rt_cart', ['identifier' => 'c-3', 'instance' => 'default', 'updated_at' => $this->now->subDays(400)]);

        $ran = app(RetentionSweeper::class)->tick($this->now);

        $this->assertSame('test.wishlist', $ran);
        $this->assertSame(['c-2', 'c-3'], $this->rows('pdt_rt_cart')->orderBy('identifier')->pluck('identifier')->all());
        $state = $this->stateOf('test.wishlist');
        $this->assertSame(1, (int) $state->last_rows);
        $this->assertSame(1, (int) $state->total_rows);
        $this->assertNull($state->last_error);
    }

    public function test_nothing_is_cleaned_in_the_30_days_after_an_item_first_appears_on_a_site(): void
    {
        $this->insertRow('pdt_rt_log', ['content' => 'old', 'created_at' => $this->now->subYears(3)]);

        $this->assertNull(app(RetentionSweeper::class)->tick($this->now));
        $this->assertNull(app(RetentionSweeper::class)->tick($this->now->addDays(29)));
        $this->assertSame(1, $this->rows('pdt_rt_log')->count());
        $first = CarbonImmutable::parse((string) $this->stateOf('test.admin_log')->first_allowed_at);
        $this->assertSame($this->now->addDays(30)->format('Y-m-d H:i'), $first->format('Y-m-d H:i'));

        $this->assertSame('test.wishlist', app(RetentionSweeper::class)->tick($this->now->addDays(31)));
        $this->assertSame('test.admin_log', app(RetentionSweeper::class)->tick($this->now->addDays(31)));
        $this->assertSame(0, $this->rows('pdt_rt_log')->count());
    }

    public function test_the_administrators_log_loses_only_rows_older_than_its_period_and_no_row_is_edited(): void
    {
        $this->allowCleaning($this->now);
        $this->insertRow('pdt_rt_log', ['content' => 'too old', 'created_at' => $this->now->subDays(400)]);
        $this->insertRow('pdt_rt_log', ['content' => 'customer an.nguyen@example.com changed', 'created_at' => $this->now->subDays(100)]);

        $sweeper = app(RetentionSweeper::class);
        $sweeper->slice(PersonalDataRegistry::retentionItem('test.admin_log'), $this->now);

        $this->assertSame(['customer an.nguyen@example.com changed'], $this->rows('pdt_rt_log')->pluck('content')->all());
    }

    public function test_an_item_runs_at_most_once_an_hour_once_its_rows_are_done(): void
    {
        $this->allowCleaning($this->now);
        $sweeper = app(RetentionSweeper::class);
        $this->assertSame('test.wishlist', $sweeper->tick($this->now));
        $this->assertSame('test.admin_log', $sweeper->tick($this->now->addMinute()));
        $this->insertRow('pdt_rt_cart', ['identifier' => 'c-9', 'instance' => 'wishlist', 'updated_at' => $this->now->subDays(300)]);

        $this->assertNull($sweeper->tick($this->now->addMinutes(30)));
        $this->assertSame(1, $this->rows('pdt_rt_cart')->count());

        $this->assertSame('test.wishlist', $sweeper->tick($this->now->addMinutes(61)));
        $this->assertSame(0, $this->rows('pdt_rt_cart')->count());
    }

    public function test_a_slice_deletes_at_most_the_slice_limit_and_the_item_stays_due_while_rows_remain(): void
    {
        $this->allowCleaning($this->now);
        $rows = [];
        for ($i = 0; $i < RetentionSweeper::ROW_LIMIT + 30; $i++) {
            $rows[] = ['content' => 'row ' . $i, 'created_at' => $this->now->subDays(500)->addSeconds($i)];
        }
        $this->rows('pdt_rt_log')->insert($rows);
        $item = PersonalDataRegistry::retentionItem('test.admin_log');
        $sweeper = app(RetentionSweeper::class);

        $first = $sweeper->slice($item, $this->now);

        $this->assertSame(RetentionSweeper::ROW_LIMIT, $first['rows']);
        $this->assertTrue($first['more']);
        $this->assertSame(30, $this->rows('pdt_rt_log')->count());
        $this->assertSame('row ' . RetentionSweeper::ROW_LIMIT, $this->rows('pdt_rt_log')->orderBy('id')->value('content'), 'the oldest rows go first');
        $this->assertSame($this->now->format('Y-m-d H:i:s'), CarbonImmutable::parse((string) $this->stateOf('test.admin_log')->next_run_at)->format('Y-m-d H:i:s'));
        $this->assertSame(30, $sweeper->slice($item, $this->now)['rows']);
        $this->assertSame(RetentionSweeper::ROW_LIMIT + 30, (int) $this->stateOf('test.admin_log')->total_rows);
    }

    public function test_an_item_turned_off_never_runs_and_run_now_says_so(): void
    {
        $this->allowCleaning($this->now);
        $item = PersonalDataRegistry::retentionItem('test.wishlist');
        app(RetentionPolicies::class)->save($item, false, 180);
        $this->insertRow('pdt_rt_cart', ['identifier' => 'c-1', 'instance' => 'wishlist', 'updated_at' => $this->now->subDays(400)]);
        $sweeper = app(RetentionSweeper::class);

        $this->assertSame('test.admin_log', $sweeper->tick($this->now));
        $this->assertNull($sweeper->tick($this->now->addMinute()));
        $this->assertSame('off', $sweeper->runNow($item, false, $this->now)['blocked']);
        $this->assertSame(1, $this->rows('pdt_rt_cart')->count());
    }

    public function test_run_now_before_the_first_allowed_clean_waits_unless_the_owner_confirms_cleaning_early(): void
    {
        $this->insertRow('pdt_rt_log', ['content' => 'old', 'created_at' => $this->now->subYears(2)]);
        $item = PersonalDataRegistry::retentionItem('test.admin_log');
        $sweeper = app(RetentionSweeper::class);

        $this->assertSame('not_yet', $sweeper->runNow($item, false, $this->now)['blocked']);
        $this->assertSame(1, $this->rows('pdt_rt_log')->count());

        $result = $sweeper->runNow($item, true, $this->now);

        $this->assertNull($result['blocked']);
        $this->assertSame(1, $result['rows']);
        $this->assertTrue(RetentionSweeper::allowed($this->stateOf('test.admin_log'), $this->now));
    }

    public function test_the_days_set_by_the_owner_decide_what_is_old(): void
    {
        $this->allowCleaning($this->now);
        $item = PersonalDataRegistry::retentionItem('test.wishlist');
        app(RetentionPolicies::class)->save($item, true, 30);
        $this->insertRow('pdt_rt_cart', ['identifier' => 'c-1', 'instance' => 'wishlist', 'updated_at' => $this->now->subDays(45)]);

        $this->assertSame(1, app(RetentionSweeper::class)->dueCount($item, $this->now));
        app(RetentionSweeper::class)->slice($item, $this->now);

        $this->assertSame(0, $this->rows('pdt_rt_cart')->count());
    }

    public function test_old_closed_orders_are_anonymised_through_the_erasure_engine_with_money_kept_and_no_ledger_entry(): void
    {
        $this->allowCleaning($this->now);
        $item = PersonalDataRegistry::retentionItem('test.orders');
        app(RetentionPolicies::class)->save($item, true, 3650);
        $old = (int) $this->insertRow('pdt_rt_order', ['customer_id' => 'c-1', 'email' => 'an@example.com', 'first_name' => 'Nguyen Van An', 'phone' => '0912345678', 'status' => 5, 'total' => 1700000, 'created_at' => $this->now->subYears(11)]);
        $this->insertRow('pdt_rt_order_note', ['order_id' => $old, 'content' => 'Called Nguyen Van An on 0912345678. Paid 1700000.']);
        $open = (int) $this->insertRow('pdt_rt_order', ['customer_id' => 'c-1', 'first_name' => 'Nguyen Van An', 'status' => 2, 'total' => 50, 'created_at' => $this->now->subYears(11)]);
        $recent = (int) $this->insertRow('pdt_rt_order', ['customer_id' => 'c-1', 'first_name' => 'Nguyen Van An', 'status' => 5, 'total' => 60, 'created_at' => $this->now->subYears(2)]);
        $ledger = $this->rows(PersonalDataSchema::ERASURE)->count();
        $before = (string) $this->rows('pdt_rt_order')->sum('total');

        $this->assertSame(1, app(RetentionSweeper::class)->dueCount($item, $this->now));
        $result = app(RetentionSweeper::class)->slice($item, $this->now);
        $this->assertSame(1, $result['rows']);
        $job = app(JobRepository::class)->activeOfKind(JobRepository::KIND_RETENTION);
        $this->assertNotNull($job);
        $this->assertSame(JobRepository::DONE, app(ErasureJobRunner::class)->runSlice($job, 30));

        $order = $this->rows('pdt_rt_order')->where('id', $old)->first();
        $this->assertSame(ColumnRule::MARKER, $order->first_name);
        $this->assertNull($order->email);
        $this->assertNull($order->phone);
        $note = (string) $this->rows('pdt_rt_order_note')->where('order_id', $old)->value('content');
        $this->assertStringNotContainsString('Nguyen Van An', $note);
        $this->assertStringNotContainsString('0912345678', $note);
        $this->assertStringContainsString('1700000', $note);
        $this->assertSame('Nguyen Van An', $this->rows('pdt_rt_order')->where('id', $open)->value('first_name'), 'an order not closed stays');
        $this->assertSame('Nguyen Van An', $this->rows('pdt_rt_order')->where('id', $recent)->value('first_name'), 'a recent order stays');
        $this->assertSame($before, (string) $this->rows('pdt_rt_order')->sum('total'));
        $this->assertSame($ledger, $this->rows(PersonalDataSchema::ERASURE)->count(), 'not a person erased: no ledger entry');
        $this->assertSame(0, app(RetentionSweeper::class)->dueCount($item, $this->now), 'an anonymised order is not picked again');
    }

    public function test_only_one_anonymisation_job_runs_at_a_time(): void
    {
        $this->allowCleaning($this->now);
        $item = PersonalDataRegistry::retentionItem('test.orders');
        app(RetentionPolicies::class)->save($item, true, 3650);
        $this->insertRow('pdt_rt_order', ['first_name' => 'A', 'status' => 6, 'created_at' => $this->now->subYears(12)]);
        $sweeper = app(RetentionSweeper::class);
        $sweeper->slice($item, $this->now);

        $second = $sweeper->slice($item, $this->now);

        $this->assertSame(0, $second['rows']);
        $this->assertTrue($second['more'], 'the item stays due until the running job ends');
        $this->assertSame(1, $this->rows(PersonalDataSchema::JOB)->where('kind', JobRepository::KIND_RETENTION)->count());
    }

    public function test_a_failed_anonymisation_job_stops_new_ones_until_the_owner_runs_it_again(): void
    {
        $this->allowCleaning($this->now);
        $item = PersonalDataRegistry::retentionItem('test.orders');
        app(RetentionPolicies::class)->save($item, true, 3650);
        $this->insertRow('pdt_rt_order', ['first_name' => 'A', 'status' => 6, 'created_at' => $this->now->subYears(12)]);
        $sweeper = app(RetentionSweeper::class);
        $sweeper->slice($item, $this->now);
        $job = app(JobRepository::class)->activeOfKind(JobRepository::KIND_RETENTION);
        $this->rows(PersonalDataSchema::JOB)->where('id', $job)->update(['status' => JobRepository::FAILED, 'attempts' => 3]);

        $again = $sweeper->slice($item, $this->now->addHours(2));

        $this->assertNotNull($again['error']);
        $this->assertSame(1, $this->rows(PersonalDataSchema::JOB)->where('kind', JobRepository::KIND_RETENTION)->count(), 'no new job for the same records');
        $this->assertSame(1, (int) $this->stateOf('test.orders')->errors);

        $sweeper->runNow($item, false, $this->now->addHours(3));

        $this->assertSame(JobRepository::QUEUED, $this->rows(PersonalDataSchema::JOB)->where('id', $job)->value('status'), 'run now gives the failed job its attempts again');
        $this->assertSame(1, $this->rows(PersonalDataSchema::JOB)->where('kind', JobRepository::KIND_RETENTION)->count());
    }

    public function test_an_item_turned_on_later_waits_30_days_like_a_new_one(): void
    {
        $this->allowCleaning($this->now);
        $item = PersonalDataRegistry::retentionItem('test.orders');
        app(RetentionPolicies::class)->save($item, true, 3650);

        app(\GP247\Core\PersonalData\Infrastructure\Retention\RetentionStateRepository::class)->setEnabledAt($item->key, $this->now);

        $this->assertFalse(RetentionSweeper::allowed($this->stateOf('test.orders'), $this->now->addDays(29)));
        $this->assertTrue(RetentionSweeper::allowed($this->stateOf('test.orders'), $this->now->addDays(31)));
    }

    public function test_an_item_naming_an_undeclared_table_keeps_its_error_and_never_breaks_the_tick(): void
    {
        PersonalDataRegistry::retention(RetentionItem::rows('test.stray', 'pdt_contact')->days(30, 1, 60)->describe('test.stray', 'tests', 1));
        PersonalDataRegistry::retention(RetentionItem::rows('test.stray2', 'pdt_rt_order_note')->days(30, 1, 60)->describe('test.stray2', 'tests', 2));
        $this->allowCleaning($this->now);
        $this->insertRow('pdt_rt_order_note', ['order_id' => 1, 'content' => 'kept']);

        $this->assertSame('test.stray', app(RetentionSweeper::class)->tick($this->now));
        $this->assertSame('test.stray2', app(RetentionSweeper::class)->tick($this->now));

        $this->assertSame(\LogicException::class, $this->stateOf('test.stray2')->last_error, 'a source whose rows are anonymised is not deleted by age');
        $this->assertSame(1, (int) $this->stateOf('test.stray2')->errors);
        $this->assertSame(1, $this->rows('pdt_rt_order_note')->count());
        $this->assertSame('test.wishlist', app(RetentionSweeper::class)->tick($this->now), 'the other items still run');
    }
}
