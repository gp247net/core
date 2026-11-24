<?php

namespace GP247\Core\Tests\Feature\PersonalData;

use GP247\Core\PersonalData\Application\Erasure\ErasureJobRunner;
use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Domain\ColumnRule;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\ErasureBlocker;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Erasing a customer goes through one service: rows are deleted or anonymised as declared,
 * free text and JSON lose the customer's values, money stays as it was, and the ledger keeps
 * only the customer id and salted email hashes.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise, US-personal-data-registry-free-text-scrub, US-personal-data-registry-erasure-ledger
 * @aidlc-adr personal-data-registry_erasure-job, personal-data-registry_anonymisation-rules, personal-data-registry_erasure-ledger
 */
#[Group('personal-data-registry')]
class PersonalDataErasureTest extends TestCase
{
    use DatabaseTransactions;
    use UsesPersonalDataFixtures;

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

    public function test_erasing_a_customer_removes_the_account_and_contact_messages_and_anonymises_every_order(): void
    {
        $ids = $this->seedCustomerWithOrders();

        $outcome = app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin', 'admin', 'a-1');

        $this->assertTrue($outcome->isDone());
        $this->assertFalse($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
        $this->assertSame(0, $this->rows('pdt_contact')->where('email', 'an.nguyen@example.com')->count());
        $order = $this->rows('pdt_order')->where('id', $ids['order'])->first();
        $this->assertSame(ColumnRule::MARKER, $order->first_name);
        $this->assertNull($order->email);
        $this->assertNull($order->phone);
        $this->assertNull($order->address1);
        $this->assertNull($order->comment);
        $this->assertSame($ids['customer'], $order->customer_id, 'the order keeps its customer id');
    }

    public function test_a_guest_order_placed_with_the_customers_email_is_anonymised_too_but_another_customers_order_is_not(): void
    {
        $ids = $this->seedCustomerWithOrders();

        app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');

        $guest = $this->rows('pdt_order')->where('id', $ids['guestOrder'])->first();
        $this->assertSame(ColumnRule::MARKER, $guest->first_name);
        $this->assertNull($guest->email);
        $other = $this->rows('pdt_order')->where('id', $ids['otherOrder'])->first();
        $this->assertSame('Tran Thi Binh', $other->first_name);
        $this->assertSame('an.nguyen@example.com', $other->email);
        $this->assertTrue($this->rows('pdt_customer')->where('id', 'c-0002')->exists());
    }

    public function test_order_amounts_are_the_same_before_and_after_the_erasure(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $before = (string) $this->rows('pdt_order')->sum('total');
        $count = $this->rows('pdt_order')->count();

        app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');

        $this->assertSame($before, (string) $this->rows('pdt_order')->sum('total'));
        $this->assertSame($count, $this->rows('pdt_order')->count());
    }

    public function test_order_history_loses_the_customers_values_but_keeps_amounts_and_business_text(): void
    {
        $ids = $this->seedCustomerWithOrders();

        app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');

        $history = $this->rows('pdt_order_history')->where('order_id', $ids['order'])->orderBy('id')->pluck('content')->all();
        $this->assertStringNotContainsString('Nguyen Trai', $history[0]);
        $this->assertStringNotContainsString('84912345678', $history[0]);
        $this->assertStringContainsString('Total 1700000', $history[0], 'a postcode-like number must not eat an amount');
        $this->assertStringContainsString(ColumnRule::MARKER, $history[0]);
        $this->assertSame('Status changed to Shipped', $history[1]);
    }

    public function test_the_booked_shipment_json_loses_the_receiver_but_keeps_its_other_keys(): void
    {
        $ids = $this->seedCustomerWithOrders();

        app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');

        $request = json_decode((string) $this->rows('pdt_order')->where('id', $ids['order'])->value('request'), true);
        $this->assertNull($request['destination']['name']);
        $this->assertNull($request['destination']['phone']);
        $this->assertNull($request['destination']['line1']);
        $this->assertNull($request['note']);
        $this->assertSame('Hanoi', $request['destination']['city']);
        $this->assertSame(1200, $request['weight']);
    }

    public function test_the_ledger_keeps_the_customer_id_and_email_hashes_but_never_the_erased_values(): void
    {
        $ids = $this->seedCustomerWithOrders();

        $outcome = app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');

        $entry = $this->rows(PersonalDataSchema::ERASURE)->where('id', $outcome->erasureId)->first();
        $this->assertSame($ids['customer'], $entry->customer_id);
        $this->assertNotNull($entry->completed_at);
        $hashes = $this->rows(PersonalDataSchema::ERASURE_EMAIL)->where('erasure_id', $outcome->erasureId)->pluck('email_hash')->all();
        $this->assertCount(1, $hashes, 'An.Nguyen@Example.com and an.nguyen@example.com are one address');
        $dump = json_encode([$entry, $hashes]);
        foreach (['an.nguyen', 'Nguyen Van An', '912 345', 'Nguyen Trai'] as $value) {
            $this->assertStringNotContainsStringIgnoringCase($value, (string) $dump);
        }
        $job = $this->rows(PersonalDataSchema::JOB)->where('id', $outcome->jobId)->first();
        $this->assertSame('done', $job->status);
        $this->assertNull($job->cursor, 'nothing of the customer stays in the finished job');
    }

    public function test_an_erased_customer_is_found_in_the_ledger_by_id_or_by_exact_email_only(): void
    {
        $ids = $this->seedCustomerWithOrders();
        app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');
        $ledger = app(\GP247\Core\PersonalData\Application\ErasureLedger::class);

        $this->assertTrue($ledger->contains($ids['customer']));
        $this->assertTrue($ledger->contains(null, ' AN.NGUYEN@example.com '));
        $this->assertFalse($ledger->contains(null, 'nguyen@example.com'));
        $this->assertFalse($ledger->contains('c-0002'));
    }

    public function test_a_job_stopped_mid_way_finishes_on_the_next_slice_with_the_same_result(): void
    {
        $ids = $this->seedCustomerWithOrders();

        $outcome = app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin', runNow: false);
        $first = app(ErasureJobRunner::class)->runSlice((string) $outcome->jobId, 0.0);
        $this->assertSame('running', $first);
        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());

        $this->assertSame('done', app(ErasureService::class)->continue((string) $outcome->jobId));
        $this->assertFalse($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
        $this->assertNull($this->rows('pdt_order')->where('id', $ids['order'])->value('email'));
    }

    public function test_erasing_the_same_customer_twice_changes_nothing_more(): void
    {
        $ids = $this->seedCustomerWithOrders();
        app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');
        $after = $this->rows('pdt_order')->orderBy('id')->get()->toArray();

        $second = app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');

        $this->assertTrue($second->isDone());
        $this->assertEquals($after, $this->rows('pdt_order')->orderBy('id')->get()->toArray());
    }

    public function test_a_blocker_stops_the_erasure_before_anything_is_written(): void
    {
        $ids = $this->seedCustomerWithOrders();
        PersonalDataRegistry::blocker(new class implements ErasureBlocker {
            public function blockers(DataSubject $subject): array
            {
                return [['reason' => 'open_order', 'ref' => '42', 'label' => 'Order #42 is being shipped']];
            }
        });
        $ledgerRows = $this->rows(PersonalDataSchema::ERASURE)->count();

        $outcome = app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');

        $this->assertTrue($outcome->isBlocked());
        $this->assertSame('open_order', $outcome->blockers[0]['reason']);
        $this->assertSame($ledgerRows, $this->rows(PersonalDataSchema::ERASURE)->count());
        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
    }

    public function test_a_failing_erasure_is_retried_then_marked_failed_and_its_ledger_entry_stays_open(): void
    {
        $ids = $this->seedCustomerWithOrders();
        PersonalDataRegistry::declare(
            PersonalDataSource::table('pdt_contact')->link(SubjectLink::email('email'))->deleteRows()->order(60)
                ->whereUsing(function () {
                    throw new \RuntimeException('broken source');
                })
        );

        $outcome = app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin');
        $service = app(ErasureService::class);
        $status = $outcome->status;
        for ($i = 0; $i < 3 && $status !== 'failed'; $i++) {
            $status = $service->continue((string) $outcome->jobId);
        }

        $this->assertSame('failed', $status);
        $entry = $this->rows(PersonalDataSchema::ERASURE)->where('id', $outcome->erasureId)->first();
        $this->assertNull($entry->completed_at);
        $this->assertStringNotContainsString('broken source', (string) $this->rows(PersonalDataSchema::JOB)->where('id', $outcome->jobId)->value('error'));
    }

    public function test_an_unknown_origin_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(ErasureService::class)->request(DataSubject::customer('c-0001'), 'someone');
    }
}
