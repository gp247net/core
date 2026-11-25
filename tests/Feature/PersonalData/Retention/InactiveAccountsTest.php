<?php

namespace GP247\Core\Tests\Feature\PersonalData\Retention;

use Carbon\CarbonImmutable;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Requests\CustomerErasures;
use GP247\Core\PersonalData\Application\Requests\PrivacyRequests;
use GP247\Core\PersonalData\Application\Retention\InactiveAccounts;
use GP247\Core\PersonalData\Application\Retention\RetentionPolicies;
use GP247\Core\PersonalData\Application\Retention\RetentionSweeper;
use GP247\Core\PersonalData\Domain\Requests\PrivacyMailer;
use GP247\Core\PersonalData\Domain\Requests\RequestSource;
use GP247\Core\PersonalData\Domain\Requests\RequestStatus;
use GP247\Core\PersonalData\Domain\Retention\RetentionItem;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\PersonalData\Infrastructure\Requests\PersonalDataInstaller;
use GP247\Core\PersonalData\Infrastructure\Retention\RetentionStateRepository;
use GP247\Core\Tests\Feature\PersonalData\Retention\Support\RecordingRetentionMailer;
use GP247\Core\Tests\Feature\PersonalData\Retention\Support\UsesRetentionFixtures;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Accounts unused for the retention period (off by default): each gets an erasure request that
 * waits 30 days, the customer is told by email when the address is verified and the notice is
 * written in the request history; signing in again calls it off; when it runs it is the
 * customer's own erasure flow.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-inactive-accounts
 * @aidlc-adr personal-data-retention_inactive-accounts
 */
#[Group('personal-data-retention')]
class InactiveAccountsTest extends TestCase
{
    use DatabaseTransactions;
    use UsesPersonalDataFixtures;
    use UsesRetentionFixtures;

    private CarbonImmutable $now;
    private RecordingRetentionMailer $mail;
    /** @var list<string> Accounts the package reports as unused (ids). */
    private array $inactive = [];
    /** @var list<string> Accounts the package reports as used since a date (ids). */
    private array $activeAgain = [];

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
        $this->mail = new RecordingRetentionMailer();
        $this->app->instance(PrivacyMailer::class, $this->mail);
        PersonalDataRegistry::customerResolver(function (string $idOrEmail): ?array {
            $row = $this->rows('pdt_customer')->where(str_contains($idOrEmail, '@') ? 'email' : 'id', $idOrEmail)->first();

            return $row === null ? null : ['id' => (string) $row->id, 'email' => $row->email, 'name' => $row->first_name, 'store_id' => '1', 'verified' => $row->id === 'c-0001'];
        });
        PersonalDataRegistry::retention(
            RetentionItem::accounts('test.accounts')
                ->findAccountsWith(function (\DateTimeInterface $since, ?string $after, int $limit): array {
                    $ids = array_values(array_filter($this->inactive, fn (string $id) => $after === null || strcmp($id, $after) > 0));

                    return array_map(fn (string $id) => ['id' => $id, 'store_id' => '1'], array_slice($ids, 0, $limit));
                })
                ->checkActivityWith(fn (string $id, \DateTimeInterface $since): bool => in_array($id, $this->activeAgain, true))
                ->days(1095, 365, 3650)->risky(['customers'])->describe('test.accounts', 'tests', 40)
        );
        $this->insertRow('pdt_customer', ['id' => 'c-0001', 'email' => 'an.nguyen@example.com', 'first_name' => 'Nguyen Van An']);
        $this->insertRow('pdt_customer', ['id' => 'c-0002', 'email' => 'binh@example.com', 'first_name' => 'Tran Thi Binh']);
        $this->inactive = ['c-0001', 'c-0002'];
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->tearDownPersonalData();
        parent::tearDown();
    }

    public function test_unused_accounts_are_off_by_default_so_no_account_is_touched(): void
    {
        $this->allowCleaning($this->now);

        app(RetentionSweeper::class)->tick($this->now);
        app(RetentionSweeper::class)->tick($this->now->addMinute());

        $this->assertSame(0, $this->rows(PersonalDataSchema::REQUEST)->count());
        $this->assertNull($this->stateOf('test.accounts')->last_run_at);
    }

    public function test_an_unused_account_gets_an_erasure_that_waits_30_days_and_only_a_verified_address_is_told(): void
    {
        $this->turnOn(since: $this->now->subYears(4));

        $result = app(RetentionSweeper::class)->slice(PersonalDataRegistry::retentionItem('test.accounts'), $this->now);

        $this->assertSame(2, $result['rows']);
        $requests = $this->rows(PersonalDataSchema::REQUEST)->orderBy('customer_id')->get();
        $this->assertCount(2, $requests);
        foreach ($requests as $request) {
            $this->assertSame(RequestSource::Retention->value, $request->source);
            $this->assertSame(RequestStatus::Waiting->value, $request->status);
            $this->assertSame($this->now->addDays(InactiveAccounts::NOTICE_DAYS)->format('Y-m-d'), CarbonImmutable::parse((string) $request->wait_until)->format('Y-m-d'));
        }
        $this->assertSame([['inactiveAccount', 'an.nguyen@example.com']], $this->mail->sent, 'binh@example.com is not verified');
        $events = $this->rows(PersonalDataSchema::REQUEST_LOG)->where('request_id', $requests[0]->id)->orderBy('created_at')->pluck('event')->all();
        $this->assertSame(['received', 'retention_notice'], $events);
        $this->assertStringContainsString('"emailed":true', (string) $this->rows(PersonalDataSchema::REQUEST_LOG)->where('request_id', $requests[0]->id)->where('event', 'retention_notice')->value('context'));
    }

    public function test_an_item_turned_on_less_than_the_period_ago_tells_no_one_yet(): void
    {
        $this->turnOn(since: $this->now->subYears(2));

        $result = app(RetentionSweeper::class)->slice(PersonalDataRegistry::retentionItem('test.accounts'), $this->now);

        $this->assertSame(0, $result['rows']);
        $this->assertSame(0, $this->rows(PersonalDataSchema::REQUEST)->count());
        $this->assertSame([], $this->mail->sent);
    }

    public function test_an_account_with_an_open_request_gets_no_second_one(): void
    {
        $this->turnOn(since: $this->now->subYears(4));
        app(CustomerErasures::class)->request('c-0002', '1', RequestSource::Customer, ['type' => 'customer', 'id' => 'c-0002']);

        app(RetentionSweeper::class)->slice(PersonalDataRegistry::retentionItem('test.accounts'), $this->now);

        $this->assertSame(1, $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', 'c-0002')->count());
        $this->assertSame(RequestSource::Customer->value, $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', 'c-0002')->value('source'));
    }

    public function test_signing_in_again_calls_off_the_erasure_of_an_unused_account_but_not_one_the_customer_asked_for(): void
    {
        $this->turnOn(since: $this->now->subYears(4));
        $this->inactive = ['c-0001'];
        app(RetentionSweeper::class)->slice(PersonalDataRegistry::retentionItem('test.accounts'), $this->now);
        app(CustomerErasures::class)->request('c-0002', '1', RequestSource::Customer, ['type' => 'customer', 'id' => 'c-0002']);

        $this->assertTrue(app(CustomerErasures::class)->cancelRetention('c-0001'));
        $this->assertFalse(app(CustomerErasures::class)->cancelRetention('c-0002'));

        $mine = $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', 'c-0001')->first();
        $this->assertSame(RequestStatus::Cancelled->value, $mine->status);
        $this->assertSame('active_again', $this->rows(PersonalDataSchema::REQUEST_LOG)->where('request_id', $mine->id)->where('event', 'status:cancelled')->value('reason'));
        $this->assertSame(RequestStatus::Waiting->value, $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', 'c-0002')->value('status'));
    }

    public function test_when_the_wait_is_over_the_unused_account_is_erased_like_the_customers_own_request(): void
    {
        $this->turnOn(since: $this->now->subYears(4));
        $this->inactive = ['c-0001'];
        app(RetentionSweeper::class)->slice(PersonalDataRegistry::retentionItem('test.accounts'), $this->now);
        $id = (string) $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', 'c-0001')->value('id');

        $result = app(PrivacyRequests::class)->execute($id, ['type' => 'system']);

        $this->assertContains($result['status'], ['completed', 'processing', 'done']);
        $this->assertFalse($this->rows('pdt_customer')->where('id', 'c-0001')->exists());
        $this->assertSame('retention', $this->rows(PersonalDataSchema::ERASURE)->latest('requested_at')->value('origin'));
        $this->assertSame(RequestStatus::Completed->value, $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->value('status'));
        $this->assertContains(['erased', 'an.nguyen@example.com'], $this->mail->sent, 'a verified address is told the account is erased');
    }

    public function test_a_slice_takes_at_most_the_slice_limit_and_continues_after_the_last_account(): void
    {
        $this->turnOn(since: $this->now->subYears(4));
        $this->inactive = [];
        for ($i = 0; $i < InactiveAccounts::LIMIT + 3; $i++) {
            $this->inactive[] = sprintf('x-%04d', $i);
        }

        $first = app(RetentionSweeper::class)->slice(PersonalDataRegistry::retentionItem('test.accounts'), $this->now);
        $second = app(RetentionSweeper::class)->slice(PersonalDataRegistry::retentionItem('test.accounts'), $this->now);

        $this->assertSame(InactiveAccounts::LIMIT, $first['rows']);
        $this->assertTrue($first['more']);
        $this->assertSame(3, $second['rows']);
        $this->assertFalse($second['more']);
        $this->assertNull($this->stateOf('test.accounts')->cursor, 'the next round starts from the first account again');
    }

    public function test_a_customer_who_kept_the_account_with_the_link_is_not_asked_again_at_the_next_round(): void
    {
        $this->turnOn(since: $this->now->subYears(4));
        $this->inactive = ['c-0001'];
        $item = PersonalDataRegistry::retentionItem('test.accounts');
        app(RetentionSweeper::class)->slice($item, $this->now);
        $id = (string) $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', 'c-0001')->value('id');
        app(CustomerErasures::class)->cancel($id, null, ['type' => 'customer']);

        $again = app(RetentionSweeper::class)->slice($item, $this->now->addHours(2));

        $this->assertSame(0, $again['rows']);
        $this->assertSame(1, $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', 'c-0001')->count());
        $this->assertCount(1, array_filter($this->mail->sent, fn ($m) => $m[0] === 'inactiveAccount'), 'one notice only');
    }

    public function test_an_account_used_during_the_notice_without_signing_in_is_kept_when_the_wait_is_over(): void
    {
        $this->turnOn(since: $this->now->subYears(4));
        $this->inactive = ['c-0001'];
        app(RetentionSweeper::class)->slice(PersonalDataRegistry::retentionItem('test.accounts'), $this->now);
        $id = (string) $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', 'c-0001')->value('id');
        $this->activeAgain = ['c-0001'];

        $result = app(PrivacyRequests::class)->execute($id, ['type' => 'system']);

        $this->assertSame(RequestStatus::Cancelled->value, $result['status']);
        $this->assertTrue($this->rows('pdt_customer')->where('id', 'c-0001')->exists());
        $this->assertSame('active_again', $this->rows(PersonalDataSchema::REQUEST_LOG)->where('request_id', $id)->where('event', 'status:cancelled')->value('reason'));
    }

    public function test_the_history_says_no_email_went_out_when_the_message_could_not_be_sent(): void
    {
        $this->turnOn(since: $this->now->subYears(4));
        $this->inactive = ['c-0001'];
        $this->app->instance(PrivacyMailer::class, new \GP247\Core\PersonalData\Infrastructure\Requests\NullPrivacyMailer());

        app(RetentionSweeper::class)->slice(PersonalDataRegistry::retentionItem('test.accounts'), $this->now);

        $id = (string) $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', 'c-0001')->value('id');
        $this->assertStringContainsString('"emailed":false', (string) $this->rows(PersonalDataSchema::REQUEST_LOG)->where('request_id', $id)->where('event', 'retention_notice')->value('context'),
            'a storefront package without this message sent nothing');
    }

    /**
     * @param CarbonImmutable $since
     * @return void
     */
    private function turnOn(CarbonImmutable $since): void
    {
        $this->allowCleaning($this->now);
        $item = PersonalDataRegistry::retentionItem('test.accounts');
        app(RetentionPolicies::class)->save($item, true, 1095);
        app(RetentionStateRepository::class)->setEnabledAt($item->key, $since);
    }
}
