<?php

namespace GP247\Core\Tests\Feature\PersonalData\Requests;

use GP247\Core\PersonalData\Application\Requests\PrivacyRequests;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Livewire\PrivacyRequestList;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Core\Tests\Feature\PersonalData\Requests\Support\UsesRequestScreen;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;

/**
 * The "Personal data requests" screen: closed to anyone without its permission, open to a role
 * given it, limited to the requests of the admin's store; an administrator creates an erasure
 * request for a customer, and erasing at once asks them to confirm who they are.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-screen, US-personal-data-requests-permission, US-personal-data-requests-admin-create
 * @aidlc-adr personal-data-requests_access-and-settings, personal-data-requests_request-model
 */
#[Group('personal-data-requests')]
class PrivacyRequestScreenTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use UsesPersonalDataFixtures;
    use UsesRequestScreen;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPersonalData();
        RequestScheduler::forgetReady();
        session()->start();
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
     * @param string $customer
     * @param string $store
     * @return string Request id.
     */
    private function waitingRequest(string $customer, string $store): string
    {
        return app(PrivacyRequests::class)->adminErase($customer, false, $store, ['type' => 'admin', 'id' => 'a-1'])['request_id'];
    }

    public function test_an_administrator_without_the_permission_is_refused(): void
    {
        $this->asUser(slugs: ['admin_customer']);

        $this->assertDenied(fn () => Livewire::test(PrivacyRequestList::class));
    }

    public function test_a_role_given_the_permission_sees_the_requests(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = $this->waitingRequest($ids['customer'], '1');
        $this->asUser(slugs: ['admin_personal_data_requests']);

        Livewire::test(PrivacyRequestList::class)->assertSee($id)->assertSeeHtml('data-testid="personal-data-requests-row"');
    }

    public function test_a_store_administrator_sees_only_the_requests_of_their_store(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $mine = $this->waitingRequest($ids['customer'], 'STO-B');
        $other = $this->waitingRequest('c-0002', '1');
        $this->asUser(slugs: ['admin_personal_data_requests']);
        session(['adminStoreId' => 'STO-B']);

        Livewire::test(PrivacyRequestList::class)->assertSee($mine)->assertDontSee($other)
            ->call('select', $other)->assertSet('selectedId', null)
            ->call('cancelRequest', $other);

        $this->assertSame('waiting', $this->rows(PersonalDataSchema::REQUEST)->where('id', $other)->value('status'));
    }

    public function test_an_administrator_creates_an_erasure_request_for_a_customer_that_waits(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $this->resolveTestCustomers([$ids['customer'] => '1']);
        $user = $this->signInAdministrator();
        RateLimiter::clear('personal-data-step-up:' . $user->id);

        $screen = Livewire::test(PrivacyRequestList::class)
            ->set('createCustomer', 'an.nguyen@example.com')->call('findCustomer')
            ->assertSet('createFound.id', $ids['customer'])
            ->set('createChannel', 'phone')->call('createRequest')
            ->assertSet('stepUpOpen', true);
        $this->assertSame(0, $this->rows(PersonalDataSchema::REQUEST)->count(), 'a waiting request still erases later: it is confirmed too');

        $screen->set('stepUpPassword', self::ADMIN_PASSWORD)->call('confirmStepUp');

        $request = $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', $ids['customer'])->first();
        $this->assertSame('waiting', $request->status);
        $this->assertSame('phone', $request->channel);
        $this->assertSame('admin', $request->source);
    }

    public function test_a_store_administrator_cannot_find_a_customer_of_another_store(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $this->resolveTestCustomers([$ids['customer'] => '1']);
        $this->asUser(slugs: ['admin_personal_data_requests']);
        session(['adminStoreId' => 'STO-B']);

        Livewire::test(PrivacyRequestList::class)
            ->set('createCustomer', $ids['customer'])->call('findCustomer')
            ->assertSet('createFound', null);
    }

    public function test_erasing_at_once_asks_the_administrator_to_confirm_who_they_are(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $this->resolveTestCustomers([$ids['customer'] => '1']);
        $user = $this->signInAdministrator();
        RateLimiter::clear('personal-data-step-up:' . $user->id);

        $screen = Livewire::test(PrivacyRequestList::class)
            ->set('createCustomer', $ids['customer'])->call('findCustomer')
            ->set('createNow', true)->call('createRequest')
            ->assertSet('stepUpOpen', true);
        $this->assertSame(0, $this->rows(PersonalDataSchema::REQUEST)->count(), 'nothing happens before the confirmation');

        $screen->set('stepUpPassword', self::ADMIN_PASSWORD)->call('confirmStepUp');

        $this->assertSame('completed', $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', $ids['customer'])->value('status'));
        $this->assertFalse($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
    }

    public function test_view_all_does_not_open_the_screen_of_customers_personal_data(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = $this->waitingRequest($ids['customer'], '1');
        $this->asUser(viewAll: true);

        $this->assertDenied(fn () => Livewire::test(PrivacyRequestList::class));
        $this->assertDenied(fn () => Livewire::test(PrivacyRequestList::class)->call('cancelRequest', $id));
        $this->assertSame('waiting', $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->value('status'));
        $this->assertContains(GP247_ADMIN_PREFIX . '/personal_data_requests', (new \GP247\Core\Middleware\PermissionMiddleware())->viewWithout());
    }

    public function test_the_operation_log_keeps_the_action_but_not_the_customer_typed_or_found_on_the_screen(): void
    {
        config(['gp247-config.admin.admin_log' => 1]);
        $ids = $this->seedCustomerWithOrders();
        $this->resolveTestCustomers([$ids['customer'] => '1']);
        $this->signInAdministrator();
        $before = (int) \GP247\Core\Models\AdminLog::max('id');

        Livewire::test(PrivacyRequestList::class)
            ->set('keyword', 'an.nguyen@example.com')
            ->set('createCustomer', 'an.nguyen@example.com')->call('findCustomer')
            ->set('createNote', 'Nguyen Van An called')->call('findCustomer');

        $rows = \GP247\Core\Models\AdminLog::where('id', '>', $before)->get();
        $this->assertNotEmpty($rows, 'the action is still logged');
        foreach ($rows as $row) {
            $this->assertStringContainsString('findCustomer', (string) $row->input);
            foreach (['an.nguyen@example.com', 'Nguyen Van An'] as $value) {
                $this->assertStringNotContainsString($value, (string) $row->input);
            }
        }
    }

    public function test_the_list_shows_who_each_request_is_about_with_one_lookup_and_the_history_in_words(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $this->resolveTestCustomers([$ids['customer'] => '1']);
        $lookups = 0;
        \GP247\Core\PersonalData\Application\PersonalDataRegistry::customersResolver(function (array $list) use (&$lookups): array {
            $lookups++;

            return ['c-0001' => ['id' => 'c-0001', 'email' => 'an.nguyen@example.com', 'name' => 'Nguyen Van An', 'store_id' => '1']];
        });
        $db = \Illuminate\Support\Facades\DB::connection(GP247_DB_CONNECTION);
        foreach (\GP247\Core\PersonalData\Infrastructure\PersonalDataLanguage::rows() as $row) {
            if ($row['location'] === 'en' && !$db->table(GP247_DB_PREFIX . 'languages')->where('code', $row['code'])->where('location', 'en')->exists()) {
                $db->table(GP247_DB_PREFIX . 'languages')->insert($row);
            }
        }
        // WHY: the language rows are memoised per process; earlier tests may have read them already.
        (new \ReflectionProperty(\GP247\Core\Models\Languages::class, 'getList'))->setValue(null, []);
        $this->signInAdministrator();
        app()->setLocale('en');
        $request = app(\GP247\Core\PersonalData\Application\Requests\CustomerErasures::class)
            ->request('c-0001', '1', \GP247\Core\PersonalData\Domain\Requests\RequestSource::Customer, ['type' => 'customer', 'id' => 'c-0001']);
        app(PrivacyRequests::class)->cancel($request['request_id'], ['type' => 'customer', 'id' => 'c-0001'], 'changed_mind');

        $screen = Livewire::test(PrivacyRequestList::class);

        $screen->assertSeeHtml('data-testid="personal-data-requests-row-customer"')->assertSee('Nguyen Van An')->assertSee('an.nguyen@example.com');
        $this->assertSame(1, $lookups, 'one lookup for the whole page');
        $screen->call('select', $request['request_id']);
        $this->assertStringNotContainsString('status:cancelled', $screen->html());
        $this->assertStringNotContainsString('changed_mind', $screen->html());
    }
}
