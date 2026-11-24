<?php

namespace GP247\Core\Tests\Feature\PersonalData\Requests;

use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Requests\PrivacyRequests;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\ErasureBlocker;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;

/**
 * An erasure an administrator asks for goes through a request: it waits for the customer to
 * change their mind (or runs at once), starts by itself when the wait is over, waits for open
 * orders, can be cancelled while waiting, and closes when the erasure ends — each step in a
 * history that keeps no personal data.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-admin-create, US-personal-data-requests-audit-log, US-personal-data-requests-screen
 * @aidlc-adr personal-data-requests_request-model, personal-data-requests_request-log, personal-data-requests_lazy-scheduling
 */
#[Group('personal-data-requests')]
class PrivacyRequestFlowTest extends TestCase
{
    use DatabaseTransactions;
    use UsesPersonalDataFixtures;

    private const ADMIN = ['type' => 'admin', 'id' => 'a-7'];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPersonalData();
        RequestScheduler::forgetReady();
        ToggleBlocker::$on = false;
        PersonalDataRegistry::blocker(new ToggleBlocker());
        Cache::flush();
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
     * @return PrivacyRequests
     */
    private function requests(): PrivacyRequests
    {
        return app(PrivacyRequests::class);
    }

    /**
     * @param string $id
     * @return object
     */
    private function request(string $id): object
    {
        return $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->first();
    }

    /**
     * @return void Run the lazy scheduler (as after an admin request).
     */
    private function tick(): void
    {
        app(RequestScheduler::class)->tick(force: true);
        for ($i = 0; $i < 10; $i++) {
            app(ErasureService::class)->resumeOne();
        }
    }

    /**
     * @param string $requestId
     * @return list<string>
     */
    private function events(string $requestId): array
    {
        return array_map(fn ($r) => (string) $r->event, app(RequestLog::class)->forRequest($requestId));
    }

    public function test_an_erasure_asked_by_an_administrator_waits_the_days_set_for_the_site_before_anything_is_erased(): void
    {
        $ids = $this->seedCustomerWithOrders();

        $result = $this->requests()->adminErase($ids['customer'], false, '1', self::ADMIN, 'email', 'Asked by phone');

        $request = $this->request($result['request_id']);
        $this->assertSame('waiting', $request->status);
        $this->assertSame(3, (int) round((strtotime($request->wait_until) - strtotime($request->received_at)) / 86400));
        $this->assertSame(20, (int) round((strtotime($request->due_at) - strtotime($request->received_at)) / 86400));
        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
        $this->assertSame(['received'], $this->events($result['request_id']));
    }

    public function test_when_the_wait_is_over_the_request_runs_by_itself_and_closes_when_the_customer_is_erased(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = $this->requests()->adminErase($ids['customer'], false, '1', self::ADMIN)['request_id'];
        $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->update(['wait_until' => now()->subMinute()]);

        $this->tick();

        $this->assertSame('completed', $this->request($id)->status);
        $this->assertFalse($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
        $this->assertSame(['received', 'status:processing', 'status:completed'], $this->events($id));
    }

    public function test_erasing_at_once_runs_the_erasure_and_completes_the_request(): void
    {
        $ids = $this->seedCustomerWithOrders();

        $result = $this->requests()->adminErase($ids['customer'], true, '1', self::ADMIN);

        $this->assertSame('completed', $this->request($result['request_id'])->status);
        $this->assertFalse($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
    }

    public function test_erasing_at_once_while_an_order_is_in_progress_creates_no_request_and_says_why(): void
    {
        $ids = $this->seedCustomerWithOrders();
        ToggleBlocker::$on = true;

        $result = $this->requests()->adminErase($ids['customer'], true, '1', self::ADMIN);

        $this->assertSame(PrivacyRequests::BLOCKED, $result['status']);
        $this->assertSame('open_order', $result['blockers'][0]['reason']);
        $this->assertSame(0, $this->rows(PersonalDataSchema::REQUEST)->count());
    }

    public function test_a_request_due_while_an_order_is_in_progress_is_held_then_runs_when_the_order_closes(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = $this->requests()->adminErase($ids['customer'], false, '1', self::ADMIN)['request_id'];
        $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->update(['wait_until' => now()->subMinute()]);
        ToggleBlocker::$on = true;

        $this->tick();
        $this->assertSame('on_hold', $this->request($id)->status);

        ToggleBlocker::$on = false;
        $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->update(['next_check_at' => now()->subMinute()]);
        $this->tick();

        $this->assertSame('completed', $this->request($id)->status);
    }

    public function test_a_waiting_request_can_be_cancelled_and_nothing_is_erased(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = $this->requests()->adminErase($ids['customer'], false, '1', self::ADMIN, 'phone', 'Customer called')['request_id'];

        $this->requests()->cancel($id, self::ADMIN, 'customer changed their mind');

        $request = $this->request($id);
        $this->assertSame('cancelled', $request->status);
        $this->assertNull($request->note, 'the note leaves with the closed request');
        $this->assertNotNull($request->closed_at);
        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
    }

    public function test_a_closed_request_cannot_be_cancelled(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = $this->requests()->adminErase($ids['customer'], true, '1', self::ADMIN)['request_id'];

        $this->expectException(\DomainException::class);

        $this->requests()->cancel($id, self::ADMIN);
    }

    public function test_asking_again_for_the_same_customer_follows_the_open_request(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $first = $this->requests()->adminErase($ids['customer'], false, '1', self::ADMIN)['request_id'];

        $second = $this->requests()->adminErase($ids['customer'], true, '1', self::ADMIN);

        $this->assertSame($first, $second['request_id']);
        $this->assertSame(1, $this->rows(PersonalDataSchema::REQUEST)->count());
        $this->assertSame('completed', $this->request($first)->status);
    }

    public function test_a_failed_erasure_marks_the_request_failed_and_it_can_be_run_again(): void
    {
        $ids = $this->seedCustomerWithOrders();
        PersonalDataRegistry::declare(PersonalDataSource::table('pdt_contact')->link(SubjectLink::email('email'))->deleteRows()->order(60)
            ->whereUsing(function () {
                throw new \RuntimeException('broken source');
            }));
        $id = $this->requests()->adminErase($ids['customer'], true, '1', self::ADMIN)['request_id'];
        for ($i = 0; $i < 3; $i++) {
            app(ErasureService::class)->continue((string) $this->request($id)->job_id);
        }

        $this->tick();
        $this->assertSame('failed', $this->request($id)->status);

        $this->declareFixtureSourcesAgain();
        $this->requests()->retry($id, self::ADMIN);
        $this->tick();
        $this->assertSame('completed', $this->request($id)->status);
    }

    public function test_the_history_and_the_closed_request_keep_no_name_email_or_note_of_the_customer(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = $this->requests()->adminErase($ids['customer'], true, '1', self::ADMIN, 'email', 'Nguyen Van An asked by mail')['request_id'];

        $text = json_encode([$this->request($id), $this->rows(PersonalDataSchema::REQUEST_LOG)->get()]);

        foreach (['Nguyen Van An', 'an.nguyen@example.com', '912 345 678', 'Nguyen Trai'] as $value) {
            $this->assertStringNotContainsStringIgnoringCase($value, (string) $text);
        }
        $this->assertNull($this->rows(PersonalDataSchema::REQUEST_LOG)->where('actor_type', 'system')->whereNotNull('ip')->value('ip'), 'no address recorded for the system or a customer');
    }

    public function test_the_history_has_no_way_to_change_or_delete_a_row(): void
    {
        $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass(RequestLog::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        $this->assertSame(['append', 'forRequest', 'export', 'prune'], $methods);
    }

    /**
     * @return void
     */
    private function declareFixtureSourcesAgain(): void
    {
        PersonalDataRegistry::declare(PersonalDataSource::table('pdt_contact')->link(SubjectLink::email('email'))->deleteRows()->order(60)->describe('test.contact', 'tests'));
    }
}

/**
 * A blocker the tests switch on and off (an order in progress).
 */
final class ToggleBlocker implements ErasureBlocker
{
    public static bool $on = false;

    /**
     * @param DataSubject $subject
     * @return list<array{reason: string, ref: string, label: string}>
     */
    public function blockers(DataSubject $subject): array
    {
        return self::$on ? [['reason' => 'open_order', 'ref' => '42', 'label' => 'Order #42']] : [];
    }
}
