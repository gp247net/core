<?php

namespace GP247\Core\Tests\Feature\PersonalData\Requests;

use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Requests\CustomerErasures;
use GP247\Core\PersonalData\Application\Requests\GuestRequests;
use GP247\Core\PersonalData\Application\Requests\PrivacyRequests;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Domain\Requests\PrivacyMailer;
use GP247\Core\PersonalData\Domain\Requests\RequestSource;
use GP247\Core\PersonalData\Infrastructure\Export\ExportStorage;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\PersonalData\Infrastructure\Requests\PersonalDataInstaller;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Customers erase their own account (with a wait to change their mind, called off from the
 * account or the email), and shoppers without an account ask by email: the address is proven by
 * a link, an erasure waits for the shop's approval, a package is prepared; every step sends the
 * email it should, to the address it should.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-customer-privacy-self-delete, US-customer-privacy-guest-request, US-personal-data-requests-guest-approval, US-customer-privacy-customer-emails, US-personal-data-requests-admin-notify
 * @aidlc-adr personal-data-requests_request-model, customer-privacy_self-service-surfaces
 */
#[Group('personal-data-requests')]
class SelfServiceRequestsTest extends TestCase
{
    use DatabaseTransactions;
    use UsesPersonalDataFixtures;

    private const CUSTOMER = ['type' => 'customer', 'id' => 'c-0001'];
    private const ADMIN = ['type' => 'admin', 'id' => 'a-7'];
    private const GUEST = 'guest.only@example.com';

    private RecordingPrivacyMailer $mail;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPersonalData();
        RequestScheduler::forgetReady();
        PersonalDataInstaller::ensure(DB::connection(GP247_DB_CONNECTION));
        Cache::flush();
        $this->mail = new RecordingPrivacyMailer();
        $this->app->instance(PrivacyMailer::class, $this->mail);
        $this->resolver(verified: true);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->rows(PersonalDataSchema::EXPORT)->pluck('id') as $id) {
            ExportStorage::remove((string) $id);
        }
        $this->tearDownPersonalData();
        parent::tearDown();
    }

    /**
     * @param bool $verified
     * @return void
     */
    private function resolver(bool $verified): void
    {
        PersonalDataRegistry::customerResolver(function (string $idOrEmail) use ($verified): ?array {
            $row = $this->rows('pdt_customer')->where(str_contains($idOrEmail, '@') ? 'email' : 'id', $idOrEmail)->first();

            return $row === null ? null : ['id' => (string) $row->id, 'email' => $row->email, 'name' => $row->first_name, 'store_id' => '1', 'verified' => $verified];
        });
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
     * @return void Run the lazy scheduler and the erasure slices.
     */
    private function tick(): void
    {
        app(RequestScheduler::class)->tick(force: true);
        for ($i = 0; $i < 10; $i++) {
            app(ErasureService::class)->resumeOne();
        }
    }

    /**
     * @return void A guest order and a message under an address with no account.
     */
    private function seedGuest(): void
    {
        $this->insertRow('pdt_order', ['customer_id' => '0', 'email' => self::GUEST, 'first_name' => 'Le Thi Hoa', 'total' => 120000]);
        $this->insertRow('pdt_contact', ['email' => self::GUEST, 'content' => 'Hello']);
    }

    /**
     * @param string $email
     * @param string $kind
     * @param string $storeId
     * @return void The public form stores the submission; the scheduler looks it up.
     */
    private function ask(string $email, string $kind, string $storeId): void
    {
        app(GuestRequests::class)->submit($email, $kind, $storeId);
        app(GuestRequests::class)->processSubmissions();
    }

    public function test_a_customer_deleting_their_account_waits_the_days_set_then_is_erased_and_told_by_email(): void
    {
        $ids = $this->seedCustomerWithOrders();

        $result = app(CustomerErasures::class)->request($ids['customer'], '1', RequestSource::Customer, self::CUSTOMER);

        $this->assertSame('waiting', $result['status']);
        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists(), 'nothing is erased while the customer may change their mind');
        $this->assertSame([['erasureScheduled', 'An.Nguyen@Example.com']], $this->mail->sent());

        $this->rows(PersonalDataSchema::REQUEST)->where('id', $result['request_id'])->update(['wait_until' => now()->subMinute()]);
        $this->tick();

        $this->assertSame('completed', $this->request($result['request_id'])->status);
        $this->assertFalse($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
        $this->assertContains(['erased', 'An.Nguyen@Example.com'], $this->mail->sent(), 'the address of the account, known only by the erasure');
    }

    public function test_with_no_wait_the_account_is_erased_at_once(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $this->rows('admin_config')->where('key', 'personal_data_erase_wait_days')->update(['value' => '0']);

        $result = app(CustomerErasures::class)->request($ids['customer'], '1', RequestSource::Customer, self::CUSTOMER);
        for ($i = 0; $i < 10; $i++) {
            app(ErasureService::class)->resumeOne();
        }

        $this->assertNotSame('waiting', $result['status']);
        $this->assertFalse($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
    }

    public function test_the_customer_calls_it_off_and_another_customer_cannot(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = app(CustomerErasures::class)->request($ids['customer'], '1', RequestSource::Customer, self::CUSTOMER)['request_id'];

        $this->assertFalse(app(CustomerErasures::class)->cancel($id, 'c-0002', self::CUSTOMER), 'not their request');
        $this->assertTrue(app(CustomerErasures::class)->cancel($id, $ids['customer'], self::CUSTOMER));

        $this->assertSame('cancelled', $this->request($id)->status);
        $this->assertNull(app(CustomerErasures::class)->pendingFor($ids['customer']));
        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
    }

    public function test_an_account_whose_email_is_not_verified_keeps_the_guest_orders_placed_with_that_email(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $this->resolver(verified: false);
        $id = app(CustomerErasures::class)->request($ids['customer'], '1', RequestSource::Customer, self::CUSTOMER)['request_id'];
        $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->update(['wait_until' => now()->subMinute()]);

        $this->tick();

        $this->assertFalse($this->rows('pdt_customer')->where('id', $ids['customer'])->exists(), 'the account itself is erased');
        $this->assertSame('An N.', $this->rows('pdt_order')->where('id', $ids['guestOrder'])->value('first_name'), 'a guest order found only by that email is not touched');
        $this->assertNotContains('erased', array_column($this->mail->sent(), 0), 'no message to an address never proven');
        $this->assertNotContains('erasureScheduled', array_column($this->mail->sent(), 0), 'not even the scheduled one: anyone can sign up with that address');
    }

    public function test_a_guest_address_with_no_data_gets_nothing_and_an_address_with_an_account_is_told_to_sign_in(): void
    {
        $this->seedCustomerWithOrders();

        $this->ask('nobody@example.com', 'export', '1');
        $this->ask('an.nguyen@example.com', 'erase', '1');

        $this->assertSame(0, $this->rows(PersonalDataSchema::REQUEST)->count());
        $this->assertSame([['signInInstead', 'an.nguyen@example.com']], $this->mail->sent());
    }

    public function test_a_guest_erasure_is_proven_by_the_link_then_waits_for_the_shop_which_approves_it(): void
    {
        $this->seedGuest();
        $this->ask(self::GUEST, 'erase', '1');
        $id = (string) $this->rows(PersonalDataSchema::REQUEST)->value('id');
        $this->assertSame('pending_verification', $this->request($id)->status);
        $this->assertSame([['verifyGuest', self::GUEST]], $this->mail->sent());
        $this->assertStringNotContainsString(self::GUEST, (string) $this->request($id)->email_enc, 'the address is kept encrypted');

        $this->assertSame('pending_approval', app(GuestRequests::class)->verify($id)['status']);
        $this->assertContains(['adminApprovalNeeded', $id], $this->mail->sent());

        app(PrivacyRequests::class)->approve($id, self::ADMIN);
        $this->assertSame('waiting', $this->request($id)->status);
        $this->assertContains(['erasureScheduled', self::GUEST], $this->mail->sent());

        $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->update(['wait_until' => now()->subMinute()]);
        $this->tick();

        $this->assertSame('completed', $this->request($id)->status);
        $this->assertSame(0, $this->rows('pdt_contact')->where('email', self::GUEST)->count());
        $this->assertNull($this->request($id)->email_enc, 'the closed request keeps no address');
    }

    public function test_the_shop_turns_a_guest_erasure_down_with_a_reason_sent_to_the_shopper(): void
    {
        $this->seedGuest();
        $this->ask(self::GUEST, 'erase', '1');
        $id = (string) $this->rows(PersonalDataSchema::REQUEST)->value('id');
        app(GuestRequests::class)->verify($id);

        try {
            app(PrivacyRequests::class)->reject($id, self::ADMIN, '   ');
            $this->fail('A reason is required');
        } catch (\DomainException $e) {
            // expected
        }
        app(PrivacyRequests::class)->reject($id, self::ADMIN, 'Orders under this email belong to a company account.');

        $this->assertSame('rejected', $this->request($id)->status);
        $this->assertContains(['rejected', self::GUEST], $this->mail->sent());
        $this->assertSame(1, $this->rows('pdt_contact')->where('email', self::GUEST)->count());
    }

    public function test_a_guest_link_not_opened_within_twenty_four_hours_ends_the_request(): void
    {
        $this->seedGuest();
        $this->ask(self::GUEST, 'export', '1');
        $id = (string) $this->rows(PersonalDataSchema::REQUEST)->value('id');
        $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->update(['checked_at' => now()->subHours(25)]);

        $this->assertNull(app(GuestRequests::class)->verify($id));
        $this->assertSame('cancelled', $this->request($id)->status);
    }

    public function test_a_guest_package_is_prepared_after_the_link_and_the_email_brings_a_link_usable_by_that_request_only(): void
    {
        if (!ExportService::zipAvailable()) {
            $this->markTestSkipped('The PHP zip extension is missing on this machine.');
        }
        $this->seedGuest();
        $this->ask(self::GUEST, 'export', '1');
        $id = (string) $this->rows(PersonalDataSchema::REQUEST)->value('id');

        $exportId = (string) app(GuestRequests::class)->verify($id)['export_id'];
        for ($i = 0; $i < 20 && app(ExportService::class)->continue($exportId) === 'building'; $i++) {
        }

        $this->assertSame('completed', $this->request($id)->status);
        $ready = array_values(array_filter($this->mail->calls, fn ($c) => $c[0] === 'exportReady'));
        $this->assertCount(1, $ready);
        $this->assertTrue($ready[0][2], 'a guest gets a signed link, an account holder signs in');
        $token = (string) app(ExportService::class)->issueToken($exportId, 'guest:' . $id);
        $this->assertNull(app(ExportService::class)->claim($exportId, $token, 'guest:another-request'));
        $this->assertNotNull(app(ExportService::class)->claim($exportId, $token, 'guest:' . $id));
    }

    public function test_the_form_only_stores_the_submission_and_one_that_needs_nothing_is_forgotten(): void
    {
        $this->seedCustomerWithOrders();

        app(GuestRequests::class)->submit('nobody@example.com', 'export', '1');
        $this->assertSame(1, $this->rows(PersonalDataSchema::REQUEST)->count(), 'the same work for every address');
        $this->assertSame([], $this->mail->sent(), 'nothing is looked up or sent while answering');

        app(GuestRequests::class)->processSubmissions();

        $this->assertSame(0, $this->rows(PersonalDataSchema::REQUEST)->count(), 'an address with no data leaves nothing behind');
    }

    public function test_an_account_address_is_told_to_sign_in_once_a_day_at_most(): void
    {
        $this->seedCustomerWithOrders();

        $this->ask('an.nguyen@example.com', 'export', '1');
        $this->ask('an.nguyen@example.com', 'erase', '1');

        $this->assertSame([['signInInstead', 'an.nguyen@example.com']], $this->mail->sent());
    }

    public function test_the_form_never_mails_more_than_the_hourly_cap_for_the_whole_site(): void
    {
        $this->seedGuest();
        for ($i = 0; $i < GuestRequests::MAILS_PER_HOUR; $i++) {
            \Illuminate\Support\Facades\RateLimiter::hit('gp247:pd-guest-mail', 3600);
        }

        $this->ask(self::GUEST, 'export', '1');

        $this->assertSame([], $this->mail->sent());
        $this->assertSame(1, $this->rows(PersonalDataSchema::REQUEST)->whereNull('checked_at')->count(), 'kept for the next hour');
        \Illuminate\Support\Facades\RateLimiter::clear('gp247:pd-guest-mail');
    }

    public function test_an_administrator_cannot_run_a_guest_erasure_that_was_not_proven_or_approved(): void
    {
        $this->seedGuest();
        $this->ask(self::GUEST, 'erase', '1');
        $id = (string) $this->rows(PersonalDataSchema::REQUEST)->value('id');

        foreach (['pending_verification', 'pending_approval'] as $status) {
            $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->update(['status' => $status]);
            try {
                app(PrivacyRequests::class)->execute($id, self::ADMIN);
                $this->fail('A ' . $status . ' request must not run');
            } catch (\DomainException $e) {
                // expected
            }
        }

        $this->assertSame(1, $this->rows('pdt_contact')->where('email', self::GUEST)->count());
    }

    public function test_the_shop_is_reminded_once_of_a_request_close_to_its_due_date(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = app(CustomerErasures::class)->request($ids['customer'], '1', RequestSource::Customer, self::CUSTOMER)['request_id'];
        $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->update(['due_at' => now()->addDays(2), 'wait_until' => now()->addDays(5)]);

        app(RequestScheduler::class)->tick(force: true);
        app(RequestScheduler::class)->tick(force: true);

        $this->assertCount(1, array_filter($this->mail->sent(), fn ($c) => $c[0] === 'adminDueSoon'));
        $this->assertContains('admin_reminded', array_map(fn ($r) => (string) $r->event, app(RequestLog::class)->forRequest($id)));
    }
}

/**
 * Records the emails a request would send.
 */
final class RecordingPrivacyMailer implements PrivacyMailer
{
    /** @var list<array{0: string, 1: string, 2?: mixed}> */
    public array $calls = [];

    /**
     * @return list<array{0: string, 1: string}> Message and recipient (or request id for the shop).
     */
    public function sent(): array
    {
        return array_map(fn (array $c) => [$c[0], $c[1]], $this->calls);
    }

    /** @inheritDoc */
    public function erasureScheduled(string $email, string $requestId, \DateTimeInterface $runsAt): void
    {
        $this->calls[] = ['erasureScheduled', $email];
    }

    /** @inheritDoc */
    public function erased(string $email): void
    {
        $this->calls[] = ['erased', $email];
    }

    /** @inheritDoc */
    public function rejected(string $email, string $reason): void
    {
        $this->calls[] = ['rejected', $email];
    }

    /** @inheritDoc */
    public function verifyGuest(string $email, string $requestId, string $kind): void
    {
        $this->calls[] = ['verifyGuest', $email];
    }

    /** @inheritDoc */
    public function signInInstead(string $email): void
    {
        $this->calls[] = ['signInInstead', $email];
    }

    /** @inheritDoc */
    public function exportReady(string $email, string $requestId, string $exportId, bool $guest): void
    {
        $this->calls[] = ['exportReady', $email, $guest];
    }

    /** @inheritDoc */
    public function adminApprovalNeeded(string $requestId): void
    {
        $this->calls[] = ['adminApprovalNeeded', $requestId];
    }

    /** @inheritDoc */
    public function adminDueSoon(string $requestId, \DateTimeInterface $dueAt): void
    {
        $this->calls[] = ['adminDueSoon', $requestId];
    }
}
