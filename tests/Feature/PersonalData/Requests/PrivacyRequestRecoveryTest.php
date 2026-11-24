<?php

namespace GP247\Core\Tests\Feature\PersonalData\Requests;

use GP247\Core\PersonalData\Application\ErasureLedger;
use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\Requests\PrivacyRequests;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Application\Requests\RequestWorkflow;
use GP247\Core\PersonalData\Domain\Requests\RequestStatus;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestRepository;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;

/**
 * A request is never erased after someone else closed it, and one whose erasure ended without
 * telling it — or never started — is brought to the status its erasure really reached; a request
 * that cannot start leaves the queue instead of holding the others.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-screen, US-personal-data-requests-admin-create
 * @aidlc-adr personal-data-requests_request-model, personal-data-requests_lazy-scheduling
 */
#[Group('personal-data-requests')]
class PrivacyRequestRecoveryTest extends TestCase
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
     * @param string $id
     * @return object
     */
    private function request(string $id): object
    {
        return $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->first();
    }

    /**
     * @return void Run the lazy scheduler once (as after an admin request).
     */
    private function tick(): void
    {
        app(RequestScheduler::class)->tick(force: true);
    }

    /**
     * @param string $id
     * @return list<array{event: string, reason: string|null}>
     */
    private function history(string $id): array
    {
        return array_map(fn ($r) => ['event' => (string) $r->event, 'reason' => $r->reason === null ? null : (string) $r->reason], app(RequestLog::class)->forRequest($id));
    }

    /**
     * @param string $id
     * @return void Put a request back to "processing" as if the end of its erasure was never heard.
     */
    private function backToProcessing(string $id): void
    {
        $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->update(['status' => 'processing', 'closed_at' => null]);
    }

    public function test_a_request_cancelled_on_another_screen_is_not_erased_when_run_from_an_old_one(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = app(PrivacyRequests::class)->adminErase($ids['customer'], false, '1', self::ADMIN)['request_id'];
        app(PrivacyRequests::class)->cancel($id, self::ADMIN);

        try {
            app(PrivacyRequests::class)->execute($id, self::ADMIN);
            $this->fail('A cancelled request must not run.');
        } catch (\DomainException $e) {
            // expected
        }

        $this->assertSame('cancelled', $this->request($id)->status);
        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
        $this->assertSame(0, $this->rows(PersonalDataSchema::JOB)->where('subject_customer_id', $ids['customer'])->count(), 'no erasure was started');
    }

    public function test_a_change_based_on_a_status_someone_else_changed_meanwhile_is_refused(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = app(PrivacyRequests::class)->adminErase($ids['customer'], false, '1', self::ADMIN)['request_id'];
        $readBefore = $this->request($id);
        app(PrivacyRequests::class)->cancel($id, self::ADMIN);

        try {
            app(RequestWorkflow::class)->transition($readBefore, RequestStatus::Processing, self::ADMIN);
            $this->fail('A move from a stale status must be refused.');
        } catch (\DomainException $e) {
            // expected
        }

        $this->assertSame('cancelled', $this->request($id)->status);
        $this->assertSame(['received', 'status:cancelled'], array_column($this->history($id), 'event'));
    }

    public function test_a_request_whose_erasure_ended_without_telling_it_is_completed_by_the_next_check(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = app(PrivacyRequests::class)->adminErase($ids['customer'], true, '1', self::ADMIN)['request_id'];
        $this->backToProcessing($id);

        $this->tick();

        $this->assertSame('completed', $this->request($id)->status);
    }

    public function test_a_request_whose_erasure_job_was_removed_after_it_finished_is_completed(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = app(PrivacyRequests::class)->adminErase($ids['customer'], true, '1', self::ADMIN)['request_id'];
        $this->backToProcessing($id);
        $this->rows(PersonalDataSchema::JOB)->where('id', $this->request($id)->job_id)->delete();

        $this->tick();

        $this->assertSame('completed', $this->request($id)->status);
    }

    public function test_a_request_whose_unfinished_erasure_disappeared_is_marked_failed(): void
    {
        $entry = app(ErasureLedger::class)->open('c-0099', [], 'admin');
        $id = app(RequestRepository::class)->create([
            'kind' => 'erase', 'source' => 'admin', 'status' => 'processing', 'customer_id' => 'c-0099', 'store_id' => '1',
            'received_at' => now(), 'due_at' => now()->addDays(20), 'wait_until' => now(),
            'executed_at' => now(), 'erasure_id' => $entry, 'job_id' => 'no-such-job',
        ]);

        $this->tick();

        $this->assertSame('failed', $this->request($id)->status);
        $this->assertSame('erasure_missing', $this->history($id)[0]['reason']);
    }

    public function test_a_request_claimed_but_never_started_is_marked_failed_after_a_while(): void
    {
        $create = fn (\DateTimeInterface $executed) => app(RequestRepository::class)->create([
            'kind' => 'erase', 'source' => 'admin', 'status' => 'processing', 'customer_id' => 'c-0098', 'store_id' => '1',
            'received_at' => now(), 'due_at' => now()->addDays(20), 'wait_until' => now(), 'executed_at' => $executed,
        ]);
        $recent = $create(now()->subMinute());
        $stuck = $create(now()->subMinutes(30));

        $this->tick();

        $this->assertSame('processing', $this->request($recent)->status, 'a request being started right now is left alone');
        $this->assertSame('failed', $this->request($stuck)->status);
        $this->assertSame('erasure_not_started', $this->history($stuck)[0]['reason']);
    }

    public function test_a_request_that_cannot_start_is_marked_failed_and_does_not_hold_the_others(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $broken = app(RequestRepository::class)->create([
            'kind' => 'erase', 'source' => 'admin', 'status' => 'waiting', 'customer_id' => null, 'store_id' => '1',
            'received_at' => now()->subDays(5), 'due_at' => now()->addDays(15), 'wait_until' => now()->subDays(2),
        ]);
        $good = app(PrivacyRequests::class)->adminErase($ids['customer'], false, '1', self::ADMIN)['request_id'];
        $this->rows(PersonalDataSchema::REQUEST)->where('id', $good)->update(['wait_until' => now()->subMinute()]);

        $this->tick();
        $this->tick();
        for ($i = 0; $i < 10; $i++) {
            app(ErasureService::class)->resumeOne();
        }

        $this->assertSame('failed', $this->request($broken)->status);
        $this->assertSame('completed', $this->request($good)->status);
    }
}
