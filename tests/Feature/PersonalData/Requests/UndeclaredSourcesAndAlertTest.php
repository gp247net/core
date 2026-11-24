<?php

namespace GP247\Core\Tests\Feature\PersonalData\Requests;

use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Requests\PrivacyRequests;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Application\Requests\UndeclaredSources;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\PersonalData\Infrastructure\Requests\PersonalDataCheck;
use GP247\Core\PersonalData\Infrastructure\Requests\PersonalDataInstaller;
use GP247\Core\PersonalData\Livewire\PrivacyRequestList;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Core\Tests\Feature\PersonalData\Requests\Support\UsesRequestScreen;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;

/**
 * The site finds tables of customer data nobody declared (typically a plugin's); a package can
 * say a table holds no customer data, an Administrator can mark one (recorded in the history);
 * the System alerts check reports failed erasures, overdue requests and undeclared tables.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-registry-undeclared-detection, US-personal-data-requests-overdue-alert, US-personal-data-requests-audit-log
 * @aidlc-adr personal-data-requests_undeclared-sources
 */
#[Group('personal-data-requests')]
class UndeclaredSourcesAndAlertTest extends TestCase
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
        PersonalDataInstaller::ensure(DB::connection(GP247_DB_CONNECTION));
        $schema = Schema::connection(GP247_DB_CONNECTION);
        if (!$schema->hasTable(GP247_DB_PREFIX . 'pdt_plugin_note')) {
            $schema->create(GP247_DB_PREFIX . 'pdt_plugin_note', function (Blueprint $t) {
                $t->increments('id');
                $t->string('author_email', 150);
                $t->text('body');
            });
            $this->reopenTransactionAfterDdl();
        }
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
     * @return list<string>
     */
    private function undeclared(): array
    {
        return array_column(app(UndeclaredSources::class)->find(), 'table');
    }

    public function test_a_plugin_table_with_an_email_column_that_nobody_declared_is_found(): void
    {
        $found = app(UndeclaredSources::class)->find();

        $this->assertContains(['table' => 'pdt_plugin_note', 'columns' => ['author_email']], $found);
        $this->assertNotContains('pdt_order', $this->undeclared(), 'declared sources are not reported');
    }

    public function test_a_table_a_package_says_holds_no_customer_data_is_not_reported(): void
    {
        PersonalDataRegistry::notCustomerData('pdt_plugin_note', 'notes of the shop staff');

        $this->assertNotContains('pdt_plugin_note', $this->undeclared());
    }

    public function test_an_administrator_marks_a_table_as_not_customer_data_and_the_history_records_it(): void
    {
        $this->signInAdministrator();

        Livewire::test(PrivacyRequestList::class)->call('markTable', $this->rowOf('pdt_plugin_note'), 'pdt_plugin_note');

        $this->assertNotContains('pdt_plugin_note', $this->undeclared());
        $row = $this->rows(PersonalDataSchema::REQUEST_LOG)->where('event', 'source_excluded')->orderByDesc('created_at')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->request_id);
        $this->assertStringContainsString('pdt_plugin_note', (string) $row->context);
    }

    public function test_a_role_given_the_screen_but_not_administrator_cannot_mark_a_table(): void
    {
        $this->asUser(slugs: ['admin_personal_data_requests']);

        Livewire::test(PrivacyRequestList::class)->call('markTable', $this->rowOf('pdt_plugin_note'), 'pdt_plugin_note');

        $this->assertContains('pdt_plugin_note', $this->undeclared());
    }

    public function test_a_mark_sent_from_a_list_that_changed_meanwhile_marks_nothing(): void
    {
        $this->signInAdministrator();
        $row = $this->rowOf('pdt_plugin_note');

        Livewire::test(PrivacyRequestList::class)->call('markTable', $row, 'pdt_some_other_table');

        $this->assertContains('pdt_plugin_note', $this->undeclared());
        $this->assertNotContains('pdt_some_other_table', \GP247\Core\PersonalData\Application\Requests\PrivacySettings::notCustomerTables());
    }

    public function test_a_plugin_table_named_without_the_site_prefix_can_be_marked_too(): void
    {
        // WHY kept like pdt_plugin_note: dropping it here would commit the mark below (DDL ends the
        // test transaction).
        $schema = Schema::connection(GP247_DB_CONNECTION);
        if (!$schema->hasTable('pdtraw_visitor')) {
            $schema->create('pdtraw_visitor', function (Blueprint $t) {
                $t->increments('id');
                $t->string('visitor_email', 150);
            });
            $this->reopenTransactionAfterDdl();
        }
        app(UndeclaredSources::class)->forget();
        $this->assertContains('pdtraw_visitor', $this->undeclared());

        app(UndeclaredSources::class)->mark('pdtraw_visitor', true, ['type' => 'admin', 'id' => 'a-1']);

        $this->assertNotContains('pdtraw_visitor', $this->undeclared());
    }

    /**
     * @param string $table
     * @return int Row of the table in the undeclared list.
     */
    private function rowOf(string $table): int
    {
        $row = array_search($table, $this->undeclared(), true);
        $this->assertIsInt($row, $table . ' is listed');

        return $row;
    }

    public function test_the_alert_reports_an_overdue_request(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = app(PrivacyRequests::class)->adminErase($ids['customer'], false, '1', ['type' => 'admin', 'id' => 'a-1'])['request_id'];
        $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->update(['due_at' => now()->subDay()]);

        $alert = (new PersonalDataCheck())->evaluate();

        $this->assertSame('warning', $alert->level);
        $this->assertStringContainsString('past their due date', $alert->detail);
    }

    public function test_the_alert_is_critical_when_an_erasure_failed(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $id = app(PrivacyRequests::class)->adminErase($ids['customer'], false, '1', ['type' => 'admin', 'id' => 'a-1'])['request_id'];
        $this->rows(PersonalDataSchema::REQUEST)->where('id', $id)->update(['status' => 'failed']);

        $this->assertSame('critical', (new PersonalDataCheck())->evaluate()->level);
    }

    public function test_the_alert_reports_undeclared_tables_and_is_quiet_when_there_is_nothing(): void
    {
        $this->assertSame('warning', (new PersonalDataCheck())->evaluate()->level);

        $this->app->instance(UndeclaredSources::class, new class {
            /** @return list<array{table: string, columns: list<string>}> */
            public function find(): array
            {
                return [];
            }
        });

        $this->assertSame('ok', (new PersonalDataCheck())->evaluate()->level);
    }
}
