<?php

namespace GP247\Core\Tests\Feature\PersonalData\Export;

use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\Export\ExportSteps;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\SubjectLookup;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;
use GP247\Core\PersonalData\Infrastructure\Export\ExportRepository;
use GP247\Core\PersonalData\Infrastructure\Export\ExportStorage;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;

/**
 * A customer's data package: every exported source of the customer and nothing else, readable
 * as JSON, CSV and a web page; built in slices that can die and resume without duplicating rows;
 * downloadable once, by the person it was given to, within 24 hours; removed afterwards.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-export-package
 * @aidlc-adr personal-data-registry_erasure-job
 */
#[Group('personal-data-registry')]
class PersonalDataExportTest extends TestCase
{
    use DatabaseTransactions;
    use UsesPersonalDataFixtures;

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
        $this->declareExportedColumns();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->made as $id) {
            ExportStorage::remove($id);
        }
        $this->tearDownPersonalData();
        parent::tearDown();
    }

    /**
     * @return void
     */
    private function declareExportedColumns(): void
    {
        PersonalDataRegistry::declare(PersonalDataSource::table('pdt_order')
            ->link(SubjectLink::customer('customer_id'))->link(SubjectLink::email('email', onlyWhenEmpty: 'customer_id'))
            ->anonymise(['first_name' => 'mark', 'email' => 'null', 'phone' => 'null', 'address1' => 'null', 'comment' => 'null', 'request' => 'null', 'total' => 'keep'])
            ->identity(['first_name', 'phone', 'address1'], ['email'])
            ->export(['id', 'email', 'first_name', 'total', 'comment'])->order(40)->describe('test.orders', 'tests'));
        PersonalDataRegistry::declare(PersonalDataSource::table('pdt_contact')->link(SubjectLink::email('email'))->deleteRows()
            ->export(['email', 'content'])->order(60)->describe('test.contact', 'tests'));
        PersonalDataRegistry::declare(PersonalDataSource::table('pdt_customer')->link(SubjectLink::customer('id'))->deleteRows()
            ->identity(['first_name', 'last_name', 'phone', 'address1'], ['email'])
            // WHY "password": a declaration listing a secret by mistake must not leak it.
            ->export(['email', 'first_name', 'phone', 'password'])->order(100)->describe('test.customer', 'tests'));
    }

    /**
     * @param string $customer
     * @return string Package id, built to the end.
     */
    private function build(string $customer): string
    {
        $id = app(ExportService::class)->start(DataSubject::customer($customer), null, 'customer', 'customer', $customer);
        $this->made[] = $id;
        for ($i = 0; $i < 20 && app(ExportService::class)->continue($id) === ExportRepository::BUILDING; $i++) {
        }

        return $id;
    }

    /**
     * @param string $id
     * @return array<string, string> File name in the ZIP => content.
     */
    private function unzip(string $id): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(ExportStorage::zipPath($id)));
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $files;
    }

    public function test_the_package_holds_every_exported_source_of_the_customer_and_nothing_of_anyone_else(): void
    {
        $ids = $this->seedCustomerWithOrders();

        $id = $this->build($ids['customer']);

        $this->assertSame('ready', app(ExportService::class)->find($id)->status);
        $files = $this->unzip($id);
        foreach (['data.json', 'index.html', 'README.txt', 'csv/pdt_order.csv', 'csv/pdt_contact.csv', 'csv/pdt_customer.csv', 'csv/pdt_order_history.csv'] as $name) {
            $this->assertArrayHasKey($name, $files);
        }
        $data = json_decode($files['data.json'], true);
        $rows = array_column($data['sources'], 'rows', 'table');
        $this->assertCount(3, $rows['pdt_order'], 'two orders of the account and the guest order placed with its email');
        $this->assertSame([$ids['order'], $ids['order'] + 1, $ids['guestOrder']], array_map('intval', array_column($rows['pdt_order'], 'id')));
        $this->assertCount(2, $rows['pdt_order_history']);
        $this->assertSame([['email' => 'An.Nguyen@Example.com', 'first_name' => 'Nguyen Van An', 'phone' => '+84 912 345 678']], $rows['pdt_customer']);
        $this->assertStringNotContainsString('Tran Thi Binh', $files['data.json'] . $files['index.html'] . implode('', $files));
    }

    public function test_secrets_and_columns_not_listed_for_export_are_never_in_the_package(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $this->rows('pdt_customer')->where('id', $ids['customer'])->update(['address1' => '12 Nguyen Trai Street']);

        $files = $this->unzip($this->build($ids['customer']));

        $all = implode("\n", $files);
        $this->assertStringNotContainsString('password', $files['data.json']);
        $this->assertStringNotContainsString('"address1"', $files['data.json'], 'address1 of the customer row is not listed for export');
        $this->assertStringNotContainsString('Leave at gate', $all, 'the order request JSON is not exported');
    }

    public function test_the_csv_opens_in_a_spreadsheet_and_the_page_shows_text_not_markup(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $this->insertRow('pdt_contact', ['email' => 'an.nguyen@example.com', 'content' => '=HYPERLINK("http://evil.example")']);

        $files = $this->unzip($this->build($ids['customer']));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $files['csv/pdt_contact.csv']);
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://evil.example\"\")\"", $files['csv/pdt_contact.csv']);
        $this->assertStringContainsString('&lt;b&gt;address1&lt;/b&gt;', $files['index.html']);
        $this->assertStringNotContainsString('<b>address1</b>', $files['index.html']);
    }

    public function test_a_slice_that_dies_after_writing_is_read_again_without_duplicating_rows(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $subject = app(SubjectLookup::class)->expand(DataSubject::customer($ids['customer']));
        $id = app(ExportRepository::class)->create(null, $ids['customer']);
        $this->made[] = $id;
        $cursor = ExportSteps::start($subject, $id, null);
        $steps = app(ExportSteps::class);

        try {
            $steps->advance($cursor, microtime(true) + 30, function () {
                throw new \RuntimeException('request died before its cursor was saved');
            });
        } catch (\RuntimeException $e) {
            // expected: the first chunk was written, the cursor was not saved
        }
        $cursor = $steps->advance($cursor, microtime(true) + 30, fn () => null);
        $steps->advance($cursor, microtime(true) + 30, fn () => null);

        $data = json_decode($this->unzip($id)['data.json'], true);
        $this->assertCount(2, array_column($data['sources'], 'rows', 'table')['pdt_order_history']);
    }

    public function test_a_download_link_works_once_for_its_owner_and_a_broken_download_can_be_tried_again(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = $this->build($ids['customer']);
        $service = app(ExportService::class);
        $owner = 'customer:' . $ids['customer'];
        $token = $service->issueToken($id, $owner);

        $this->assertNull($service->claim($id, $token, 'customer:c-0002'), 'another customer');
        $this->assertNull($service->claim($id, 'wrong-token', $owner));
        $this->assertFileExists((string) $service->claim($id, $token, $owner));
        $this->assertNull($service->claim($id, $token, $owner), 'a link is used once');
        $this->assertFalse($service->lastDownload($id), 'the file stays for another try');

        $this->assertNotNull($service->claim($id, (string) $service->issueToken($id, $owner), $owner));
        $this->assertNotNull($service->claim($id, (string) $service->issueToken($id, $owner), $owner));
        $this->assertTrue($service->lastDownload($id), 'third download is the last');
        $this->assertNull($service->issueToken($id, $owner), 'no link after the last download');
    }

    public function test_a_link_past_its_twenty_four_hours_is_refused_and_the_file_is_removed(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = $this->build($ids['customer']);
        $service = app(ExportService::class);
        $token = $service->issueToken($id, 'customer:' . $ids['customer']);
        $this->rows(PersonalDataSchema::EXPORT)->where('id', $id)->update(['expires_at' => now()->subMinute()]);

        $this->assertNull($service->claim($id, $token, 'customer:' . $ids['customer']));
        $service->prune();

        $this->assertFileDoesNotExist(ExportStorage::zipPath($id));
        $this->assertSame('expired', $service->find($id)->status);
    }

    public function test_a_package_downloaded_for_the_last_time_is_removed_from_the_server(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = $this->build($ids['customer']);
        $service = app(ExportService::class);
        for ($i = 0; $i < ExportRepository::MAX_DOWNLOADS; $i++) {
            $service->claim($id, (string) $service->issueToken($id, 'admin:a-1'), 'admin:a-1');
        }

        $service->prune();

        $this->assertFileDoesNotExist(ExportStorage::zipPath($id));
        $this->assertNotNull($service->find($id)->cleaned_at);
    }

    public function test_one_package_per_customer_per_twenty_four_hours_and_the_administrators_package_is_counted_apart(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = $this->build($ids['customer']);

        $this->assertSame($id, app(ExportService::class)->recentFor($ids['customer'])->id);
        $this->assertNull(app(ExportService::class)->recentFor($ids['customer'], ExportRepository::AUDIENCE_ADMIN));
        $this->rows(PersonalDataSchema::EXPORT)->where('id', $id)->update(['created_at' => now()->subHours(25)]);
        $this->assertNull(app(ExportService::class)->recentFor($ids['customer']));
    }

    public function test_an_account_whose_email_is_not_verified_gets_no_row_found_only_by_that_email(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $service = app(ExportService::class);
        $id = $service->start(DataSubject::customer($ids['customer']), null, 'customer', 'customer', $ids['customer'], matchEmails: false);
        $this->made[] = $id;
        for ($i = 0; $i < 20 && $service->continue($id) === ExportRepository::BUILDING; $i++) {
        }

        $rows = array_column(json_decode($this->unzip($id)['data.json'], true)['sources'], 'rows', 'table');
        $this->assertSame([$ids['order'], $ids['order'] + 1], array_map('intval', array_column($rows['pdt_order'], 'id')), 'no guest order placed with that email');
        $this->assertSame([], $rows['pdt_contact'], 'no message sent with that email');
    }

    public function test_erasing_the_customer_removes_their_packages_and_stops_one_being_built(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $ready = $this->build($ids['customer']);
        $building = app(ExportService::class)->start(DataSubject::customer($ids['customer']), null, 'admin', 'admin', 'a-1', audience: ExportRepository::AUDIENCE_ADMIN);
        $this->made[] = $building;

        app(ExportService::class)->discardFor($ids['customer']);
        app(ExportService::class)->continue($building);
        \GP247\Core\PersonalData\Application\ErasureService::forgetReady();
        app(\GP247\Core\PersonalData\Application\ErasureService::class)->resumeOne();

        $this->assertFileDoesNotExist(ExportStorage::zipPath($ready));
        $this->assertFileDoesNotExist(ExportStorage::zipPath($building));
        $this->assertSame('failed', app(ExportService::class)->find($ready)->status);
        $this->assertSame('failed', app(ExportService::class)->find($building)->status);
    }

    public function test_packing_starts_in_a_slice_of_its_own(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $service = app(ExportService::class);
        $id = $service->start(DataSubject::customer($ids['customer']), null, 'customer', 'customer', $ids['customer']);
        $this->made[] = $id;

        $this->assertSame(ExportRepository::BUILDING, $service->continue($id), 'reading ends the first slice');
        $this->assertSame(ExportRepository::READY, $service->continue($id));
    }
}
