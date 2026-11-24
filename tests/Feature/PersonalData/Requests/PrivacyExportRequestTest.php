<?php

namespace GP247\Core\Tests\Feature\PersonalData\Requests;

use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\Requests\PrivacyExports;
use GP247\Core\PersonalData\Application\Requests\PrivacyRequests;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Domain\Requests\RequestSource;
use GP247\Core\PersonalData\Infrastructure\Export\ExportStorage;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\PersonalData\Livewire\PrivacyRequestList;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Core\Tests\Feature\PersonalData\Requests\Support\UsesRequestScreen;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;

/**
 * An administrator prepares a customer's data package from the requests screen: the request
 * closes when the package is ready, the package is downloaded once by that administrator after
 * confirming who they are, and a data package request can never erase anyone.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-admin-create, US-personal-data-requests-permission, US-personal-data-requests-audit-log
 * @aidlc-adr personal-data-requests_request-model, personal-data-requests_access-and-settings
 */
#[Group('personal-data-requests')]
class PrivacyExportRequestTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use UsesPersonalDataFixtures;
    use UsesRequestScreen;

    private const ADMIN = ['type' => 'admin', 'id' => 'a-7'];

    /** @var list<string> */
    private array $made = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (!ExportService::zipAvailable()) {
            $this->markTestSkipped('The PHP zip extension is missing on this machine.');
        }
        $this->setUpPersonalData();
        RequestScheduler::forgetReady();
        Cache::flush();
        session()->start();
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
     * @param string $customer
     * @return array{request_id: string, export_id: string}
     */
    private function readyPackage(string $customer): array
    {
        $result = app(PrivacyExports::class)->request($customer, '1', RequestSource::Admin, self::ADMIN);
        for ($i = 0; $i < 20 && app(ExportService::class)->continue($result['export_id']) === 'building'; $i++) {
        }

        return ['request_id' => (string) $result['request_id'], 'export_id' => $result['export_id']];
    }

    /**
     * @param string $id
     * @return list<string>
     */
    private function events(string $id): array
    {
        return array_map(fn ($r) => (string) $r->event, app(RequestLog::class)->forRequest($id));
    }

    public function test_an_administrator_prepares_a_data_package_and_the_request_closes_when_it_is_ready(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $this->resolveTestCustomers([$ids['customer'] => '1']);
        $user = $this->signInAdministrator();
        RateLimiter::clear('personal-data-step-up:' . $user->id);

        $screen = Livewire::test(PrivacyRequestList::class)
            ->set('createCustomer', $ids['customer'])->call('findCustomer')
            ->set('createKind', 'export')->call('createRequest')
            ->set('stepUpPassword', self::ADMIN_PASSWORD)->call('confirmStepUp');
        $request = $this->rows(PersonalDataSchema::REQUEST)->where('customer_id', $ids['customer'])->first();
        $this->assertSame('export', $request->kind);
        for ($i = 0; $i < 20 && $this->rows(PersonalDataSchema::EXPORT)->where('request_id', $request->id)->value('status') === 'building'; $i++) {
            $screen->call('advanceExport', $request->id);
        }

        $this->assertSame('completed', $this->rows(PersonalDataSchema::REQUEST)->where('id', $request->id)->value('status'));
        $this->assertSame(['received', 'status:completed'], $this->events($request->id));
        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists(), 'nothing is erased');
    }

    public function test_the_package_is_downloaded_once_by_the_administrator_who_confirmed_who_they_are(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $package = $this->readyPackage($ids['customer']);
        $user = $this->signInAdministrator();
        RateLimiter::clear('personal-data-step-up:' . $user->id);

        $screen = Livewire::test(PrivacyRequestList::class)->call('downloadExport', $package['request_id'])->assertSet('stepUpOpen', true);
        $this->assertSame([], $screen->effects['redirect'] ?? [], 'no link before the confirmation');
        $screen->set('stepUpPassword', self::ADMIN_PASSWORD)->call('confirmStepUp');
        $url = (string) $screen->effects['redirect'];
        $this->assertStringContainsString('/personal_data_requests/download/' . $package['export_id'] . '/', $url);

        config(['gp247-config.admin.admin_log' => 1]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->get($url)->assertForbidden();
        $this->assertContains('export_downloaded', $this->events($package['request_id']));
        $token = substr($url, strrpos($url, '/') + 1);
        $logged = \GP247\Core\Models\AdminLog::where('path', 'like', '%personal_data_requests/download/%')->pluck('path')->all();
        $this->assertNotEmpty($logged);
        foreach ($logged as $path) {
            $this->assertStringNotContainsString($token, (string) $path, 'the operation log never holds a usable link');
        }
    }

    public function test_a_link_given_to_one_administrator_does_not_work_for_another(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $package = $this->readyPackage($ids['customer']);
        $token = (string) app(ExportService::class)->issueToken($package['export_id'], 'admin:999999');
        $this->signInAdministrator();

        $this->get(gp247_route_admin('admin.personal_data_requests.download', ['export' => $package['export_id'], 'token' => $token]))->assertForbidden();
        $this->assertFileExists(ExportStorage::zipPath($package['export_id']));
    }

    public function test_running_or_retrying_a_data_package_request_never_erases_the_customer(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $package = $this->readyPackage($ids['customer']);
        $this->rows(PersonalDataSchema::REQUEST)->where('id', $package['request_id'])->update(['status' => 'failed']);

        foreach (['execute', 'retry'] as $method) {
            try {
                app(PrivacyRequests::class)->{$method}($package['request_id'], self::ADMIN);
                $this->fail($method . ' must refuse a data package request');
            } catch (\DomainException $e) {
                // expected
            }
        }

        $this->assertTrue($this->rows('pdt_customer')->where('id', $ids['customer'])->exists());
    }

    public function test_asking_again_within_twenty_four_hours_returns_the_same_package_and_the_customer_has_their_own(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $first = $this->readyPackage($ids['customer']);

        $again = app(PrivacyExports::class)->request($ids['customer'], '1', RequestSource::Admin, self::ADMIN);
        $customers = app(PrivacyExports::class)->request($ids['customer'], '1', RequestSource::Customer, ['type' => 'customer', 'id' => $ids['customer']]);

        $this->assertTrue($again['reused']);
        $this->assertSame($first['export_id'], $again['export_id']);
        $this->assertFalse($customers['reused'], 'the administrator answering an email does not use up the own package of the customer');
        $this->assertSame(2, $this->rows(PersonalDataSchema::REQUEST)->where('kind', 'export')->count());
    }

    public function test_a_package_whose_job_gave_up_marks_its_request_failed(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $result = app(PrivacyExports::class)->request($ids['customer'], '1', RequestSource::Admin, self::ADMIN);
        $this->rows(PersonalDataSchema::JOB)->where('id', $this->rows(PersonalDataSchema::EXPORT)->where('id', $result['export_id'])->value('job_id'))->update(['status' => 'failed']);

        app(RequestScheduler::class)->tick(force: true);

        $this->assertSame('failed', $this->rows(PersonalDataSchema::REQUEST)->where('id', $result['request_id'])->value('status'));
    }
}
