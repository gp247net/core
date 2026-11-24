<?php

namespace GP247\Core\Tests\Feature\PersonalData;

use GP247\Core\Backup\Application\ConfigRestoreRules;
use GP247\Core\PersonalData\Application\ManifestDeclarations;
use GP247\Core\PersonalData\Application\Pepper;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\SubjectLookup;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;
use GP247\Core\Tests\Feature\PersonalData\Support\UsesPersonalDataFixtures;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Packages and extensions declare their customers' data; the site can then tell what it holds
 * about one customer, and the email-hash secret stays the site's own.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-declare-sources, US-personal-data-registry-subject-lookup, US-personal-data-registry-erasure-ledger
 * @aidlc-adr personal-data-registry_source-declaration, personal-data-registry_erasure-ledger
 */
#[Group('personal-data-registry')]
class PersonalDataDeclarationTest extends TestCase
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

    public function test_the_core_declares_failed_customer_sign_ins_as_kept_until_their_own_retention(): void
    {
        PersonalDataRegistry::reset();
        \GP247\Core\PersonalData\Infrastructure\CoreSources::register();

        $source = PersonalDataRegistry::source('admin_login_failure');

        $this->assertNotNull($source);
        $this->assertSame('retain', $source->rowRule()->value);
        $this->assertSame(['audience' => 'customer'], $source->equals());
    }

    public function test_an_extension_declaration_joins_the_registry_but_never_replaces_a_package_declaration(): void
    {
        PersonalDataRegistry::useManifestSources([
            'plugin_review' => ManifestDeclarations::parse(['table' => 'plugin_review', 'link' => ['customer' => 'customer_id'], 'rule' => 'delete'], 'Plugins/Review'),
            'pdt_contact' => ManifestDeclarations::parse(['table' => 'pdt_contact', 'link' => ['email' => 'email'], 'rule' => 'retain'], 'Plugins/Evil'),
        ]);

        $tables = array_map(fn (PersonalDataSource $s) => $s->table, PersonalDataRegistry::sources());

        $this->assertContains('plugin_review', $tables);
        $this->assertSame('delete', PersonalDataRegistry::source('pdt_contact')->rowRule()->value);
        $this->assertSame('tests', PersonalDataRegistry::source('pdt_contact')->owner());
    }

    public function test_sources_are_processed_children_first_and_the_customer_row_last(): void
    {
        $tables = array_map(fn (PersonalDataSource $s) => $s->table, PersonalDataRegistry::sources());

        $this->assertSame(['pdt_order_history', 'pdt_order', 'pdt_contact', 'pdt_customer'], $tables);
    }

    public function test_the_lookup_counts_a_customers_rows_including_guest_orders_with_the_same_email(): void
    {
        $ids = $this->seedCustomerWithOrders();
        $lookup = app(SubjectLookup::class);

        $subject = $lookup->expand(DataSubject::customer($ids['customer']));
        $counts = array_column($lookup->counts($subject), 'rows', 'table');

        $this->assertSame(['an.nguyen@example.com'], $subject->emails);
        $this->assertSame(3, $counts['pdt_order'], 'two own orders and one guest order, not the other customer\'s');
        $this->assertSame(1, $counts['pdt_customer']);
        $this->assertSame(1, $counts['pdt_contact']);
        $this->assertSame(1 + 1, $counts['pdt_order_history']);
    }

    public function test_a_guest_is_matched_by_email_only(): void
    {
        $this->seedCustomerWithOrders();

        $counts = array_column(app(SubjectLookup::class)->counts(DataSubject::guest('AN.NGUYEN@example.com')), 'rows', 'table');

        $this->assertSame(1, $counts['pdt_order'], 'only the guest order: account orders and other customers\' orders are not a guest\'s');
        $this->assertSame(0, $counts['pdt_customer']);
        $this->assertSame(1, $counts['pdt_contact']);
    }

    public function test_the_email_hash_secret_is_created_once_stored_encrypted_and_kept_by_configuration_restores(): void
    {
        $first = Pepper::hashEmail('A@Example.com');
        Pepper::forget();
        $second = Pepper::hashEmail(' a@example.com ');

        $this->assertSame($first, $second);
        $row = (array) $this->rows('admin_config')->where('key', Pepper::KEY)->first();
        $this->assertStringStartsWith('enc:', (string) $row['value']);
        $this->assertSame(1, (int) $row['security']);
        $this->assertTrue(ConfigRestoreRules::isBackupRow($row), 'a restore never brings back an older pepper');
    }

    public function test_a_link_to_an_undeclared_parent_matches_nothing(): void
    {
        $this->seedCustomerWithOrders();
        PersonalDataRegistry::declare(
            PersonalDataSource::table('pdt_order_history')->link(SubjectLink::parent('missing_table', 'order_id'))->anonymise(['content' => 'scrubText'])
        );

        $counts = array_column(app(SubjectLookup::class)->counts(DataSubject::customer('c-0001')), 'rows', 'table');

        $this->assertSame(0, $counts['pdt_order_history']);
    }
}
