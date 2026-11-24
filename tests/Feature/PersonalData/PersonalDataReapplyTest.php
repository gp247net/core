<?php

namespace GP247\Core\Tests\Feature\PersonalData;

use GP247\Core\Backup\Application\UndeleteVetoes;
use GP247\Core\Backup\Events\RestoreApplied;
use GP247\Core\PersonalData\Application\ErasedRowsVeto;
use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;
use GP247\Core\PersonalData\Domain\ColumnRule;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;

/**
 * When a restore puts older data back, every customer erased since that data was saved is
 * erased again — on the restored tables only — and "add back deleted records" refuses their
 * rows. The ledger entry of the erasure stays as it was.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-restore-reapply
 * @aidlc-adr personal-data-registry_restore-reapply
 */
#[Group('personal-data-registry')]
class PersonalDataReapplyTest extends TestCase
{
    use DatabaseTransactions;
    use UsesPersonalDataFixtures;

    /** Every fixture table (real names), as a restore of all of them would report. */
    private const TABLES = ['pdt_customer', 'pdt_order', 'pdt_order_history', 'pdt_contact'];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPersonalData();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->tearDownPersonalData();
        parent::tearDown();
    }

    /**
     * @param list<string> $tables  Logical names.
     * @param string       $asOf    ISO time of the restored data.
     * @return int|null What the listeners will erase again.
     */
    private function restored(array $tables, string $asOf, string $origin = RestoreApplied::ORIGIN_SITE): ?int
    {
        return RestoreApplied::dispatchAndCount(new RestoreApplied(
            'replace',
            $asOf,
            array_map(fn ($t) => GP247_DB_PREFIX . $t, $tables),
            RestoreApplied::PHASE_DONE,
            $origin,
        ));
    }

    /**
     * @return void Run every pending erasure job to the end, as later admin requests would.
     */
    private function runPendingJobs(): void
    {
        for ($i = 0; $i < 20; $i++) {
            app(ErasureService::class)->resumeOne();
        }
    }

    /**
     * Erase the seeded customer, then put the rows back as a restore of a backup made before
     * would.
     *
     * @return array{customer: string, order: int, guestOrder: int, otherOrder: int, before: array<string, list<array<string, mixed>>>}
     */
    private function eraseThenRestoreOlderData(): array
    {
        $ids = $this->seedCustomerWithOrders();
        $before = [];
        foreach (self::TABLES as $table) {
            $before[$table] = $this->rows($table)->get()->map(fn ($r) => (array) $r)->all();
        }
        app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin', 'admin', 'a-1');
        foreach (self::TABLES as $table) {
            $this->rows($table)->delete();
            $this->rows($table)->insert($before[$table]);
        }

        return $ids + ['before' => $before];
    }

    public function test_a_restore_of_data_from_before_an_erasure_erases_the_customer_again(): void
    {
        $ids = $this->eraseThenRestoreOlderData();
        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists(), 'the restore brought the customer back');

        $count = $this->restored(self::TABLES, now()->subHour()->toIso8601String());
        $this->runPendingJobs();

        $this->assertSame(1, $count, 'the restore result says one customer is erased again');
        $this->assertFalse($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
        $this->assertSame(0, $this->rows('pdt_contact')->where('email', 'an.nguyen@example.com')->count());
        $this->assertSame(ColumnRule::MARKER, $this->rows('pdt_order')->where('id', $ids['order'])->value('first_name'));
        $this->assertNull($this->rows('pdt_order')->where('id', $ids['guestOrder'])->value('email'), 'guest orders found again through the email hash');
        $this->assertSame('Tran Thi Binh', $this->rows('pdt_order')->where('id', $ids['otherOrder'])->value('first_name'));
        $this->assertStringNotContainsString('12 Nguyen Trai Street', (string) $this->rows('pdt_order_history')->where('order_id', $ids['order'])->orderBy('id')->value('content'));
    }

    public function test_only_the_restored_tables_are_touched(): void
    {
        $ids = $this->eraseThenRestoreOlderData();

        $this->restored(['pdt_contact'], now()->subHour()->toIso8601String());
        $this->runPendingJobs();

        $this->assertSame(0, $this->rows('pdt_contact')->where('email', 'an.nguyen@example.com')->count());
        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists(), 'a table the restore did not replace is left to its own restore');
    }

    public function test_an_erasure_made_before_the_restored_data_is_not_applied_again(): void
    {
        $this->eraseThenRestoreOlderData();

        $count = $this->restored(self::TABLES, now()->addHour()->toIso8601String());

        $this->assertSame(0, $count);
        $this->assertSame(0, $this->rows(PersonalDataSchema::JOB)->where('kind', 'reapply')->count());
    }

    public function test_an_erased_guest_is_found_again_by_the_hash_of_their_email(): void
    {
        $this->insertRow('pdt_contact', ['email' => 'guest@example.com', 'content' => 'Hello']);
        app(ErasureService::class)->request(DataSubject::guest('guest@example.com'), 'guest');
        $this->insertRow('pdt_contact', ['email' => 'Guest@Example.com', 'content' => 'Hello']);
        $this->insertRow('pdt_contact', ['email' => 'someone@example.com', 'content' => 'Hi']);

        $this->assertSame(1, $this->restored(['pdt_contact'], now()->subHour()->toIso8601String()));
        $this->runPendingJobs();

        $this->assertSame(0, $this->rows('pdt_contact')->whereIn('email', ['guest@example.com', 'Guest@Example.com'])->count());
        $this->assertSame(1, $this->rows('pdt_contact')->where('email', 'someone@example.com')->count());
    }

    public function test_applying_again_leaves_the_ledger_entry_as_it_was(): void
    {
        $this->eraseThenRestoreOlderData();
        $entry = (array) $this->rows(PersonalDataSchema::ERASURE)->orderByDesc('requested_at')->first();

        $this->restored(self::TABLES, now()->subHour()->toIso8601String());
        $this->runPendingJobs();

        $this->assertSame(1, $this->rows(PersonalDataSchema::ERASURE)->count());
        $this->assertEquals($entry, (array) $this->rows(PersonalDataSchema::ERASURE)->where('id', $entry['id'])->first());
        $this->assertSame('done', $this->rows(PersonalDataSchema::JOB)->where('kind', 'reapply')->value('status'));
    }

    public function test_adding_back_deleted_records_refuses_the_rows_of_an_erased_customer(): void
    {
        $ids = $this->seedCustomerWithOrders();
        app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');
        $rows = [
            ['id' => $ids['customer'], 'email' => 'other@example.com', 'first_name' => 'X'],
            ['id' => 'c-0009', 'email' => 'AN.NGUYEN@example.com', 'first_name' => 'Y'],
            ['id' => 'c-0010', 'email' => 'new@example.com', 'first_name' => 'Z'],
        ];

        $refused = UndeleteVetoes::refuse(GP247_DB_PREFIX . 'pdt_customer', $rows);

        $this->assertSame([0 => ErasedRowsVeto::REASON, 1 => ErasedRowsVeto::REASON], $refused);
    }

    public function test_an_erasure_still_running_when_the_backup_read_the_tables_is_applied_again(): void
    {
        $ids = $this->eraseThenRestoreOlderData();
        // Asked two hours ago, finished just now: the backup read one hour ago caught it half done.
        $this->rows(PersonalDataSchema::ERASURE)->update(['requested_at' => now()->subHours(2), 'completed_at' => now()]);

        $this->assertSame(1, $this->restored(self::TABLES, now()->subHour()->toIso8601String()));
        $this->runPendingJobs();

        $this->assertFalse($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
    }

    public function test_when_the_time_of_the_restored_data_is_unknown_every_erasure_is_applied_again(): void
    {
        $ids = $this->eraseThenRestoreOlderData();
        $this->rows(PersonalDataSchema::ERASURE)->update(['requested_at' => now()->subYear(), 'completed_at' => now()->subYear()]);

        $this->assertSame(1, $this->restored(self::TABLES, ''));
        $this->runPendingJobs();

        $this->assertFalse($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
    }

    public function test_an_archive_of_another_site_is_matched_by_email_only_never_by_customer_id(): void
    {
        $ids = $this->seedCustomerWithOrders();
        app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');
        // The other site's customer with the same id is someone else; the erased address comes back.
        $this->insertRow('pdt_customer', ['id' => $ids['customer'], 'email' => 'someone.else@example.com', 'first_name' => 'Pham Minh']);
        $this->insertRow('pdt_contact', ['email' => 'an.nguyen@example.com', 'content' => 'Back from the other site']);

        $this->restored(['pdt_customer', 'pdt_contact'], '', RestoreApplied::ORIGIN_OTHER);
        $this->runPendingJobs();

        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists(), 'a different person with the same id stays');
        $this->assertSame(0, $this->rows('pdt_contact')->where('email', 'an.nguyen@example.com')->count());
        $this->assertSame([], UndeleteVetoes::refuse(GP247_DB_PREFIX . 'pdt_customer', [['id' => $ids['customer'], 'email' => 'x@example.com']], false));
    }

    public function test_one_job_per_restore_reads_the_email_columns_and_no_entry_gets_two_jobs(): void
    {
        $this->eraseThenRestoreOlderData();

        $this->restored(self::TABLES, now()->subHour()->toIso8601String());
        $this->restored(self::TABLES, now()->subHour()->toIso8601String());
        app(ErasureService::class)->resumeOne();
        app(ErasureService::class)->resumeOne();

        $this->assertSame(2, $this->rows(PersonalDataSchema::JOB)->where('kind', 'reapply')->whereNull('erasure_id')->count(), 'one batch job per restore');
        $this->assertSame(1, $this->rows(PersonalDataSchema::JOB)->where('kind', 'reapply')->whereNotNull('erasure_id')->count(), 'the second restore does not queue the same entry twice');
    }

    public function test_a_guest_only_email_link_does_not_refuse_an_account_order_with_that_address(): void
    {
        app(ErasureService::class)->request(DataSubject::guest('guest@example.com'), 'guest');
        // As gp247/shop declares orders: the email links guest orders only (not the account's own).
        PersonalDataRegistry::declare(PersonalDataSource::table('pdt_order')
            ->link(SubjectLink::customer('customer_id'))
            ->link(SubjectLink::email('email', onlyWhenEmpty: 'customer_id'))
            ->anonymise(['first_name' => 'mark', 'email' => 'null'])
            ->order(40));

        $refused = UndeleteVetoes::refuse(GP247_DB_PREFIX . 'pdt_order', [
            ['id' => 900, 'customer_id' => 'c-0042', 'email' => 'guest@example.com', 'first_name' => 'Account'],
            ['id' => 901, 'customer_id' => '0', 'email' => 'guest@example.com', 'first_name' => 'Guest'],
        ]);

        $this->assertSame([1 => ErasedRowsVeto::REASON], $refused);
    }

    public function test_a_listener_answering_false_does_not_stop_the_others(): void
    {
        $this->eraseThenRestoreOlderData();
        app('events')->listen(RestoreApplied::class, fn () => false);
        app('events')->listen(RestoreApplied::class, fn () => 2);

        $this->assertSame(1 + 2, $this->restored(self::TABLES, now()->subHour()->toIso8601String()));
    }

    public function test_a_failing_listener_never_breaks_the_restore(): void
    {
        Event::listen(RestoreApplied::class, function (): void {
            throw new \RuntimeException('listener down');
        });

        $this->assertNull($this->restored(self::TABLES, now()->subHour()->toIso8601String()));
    }
}
