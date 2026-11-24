<?php

namespace GP247\Core\Tests\Unit\PersonalData;

use GP247\Core\PersonalData\Application\Erasure\JsonScrubber;
use GP247\Core\PersonalData\Application\ManifestDeclarations;
use GP247\Core\PersonalData\Domain\ColumnRule;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\KnownValues;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\RowRule;
use GP247\Core\PersonalData\Domain\SubjectLink;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Value rules of the personal data registry: known values, scrubbing, declarations, subjects.
 */
#[Group('personal-data-registry')]
class PersonalDataRulesTest extends TestCase
{
    public function test_known_values_include_escaped_and_digit_forms_and_skip_short_values(): void
    {
        $known = KnownValues::of(['Tom & Jerry', '+84 912 345 678', 'Le', null, '[deleted]']);

        $this->assertContains('Tom & Jerry', $known->all());
        $this->assertContains('Tom &amp; Jerry', $known->all());
        $this->assertContains('84912345678', $known->all());
        $this->assertNotContains('Le', $known->all());
        $this->assertNotContains('[deleted]', $known->all());
    }

    public function test_longer_values_are_replaced_before_the_values_they_contain(): void
    {
        $known = KnownValues::of(['Nguyen Trai', '12 Nguyen Trai Street']);

        $this->assertSame('Ship to [deleted].', $known->scrub('Ship to 12 Nguyen Trai Street.'));
    }

    public function test_a_numeric_value_is_not_replaced_inside_a_longer_number(): void
    {
        $known = KnownValues::of(['700000']);

        $this->assertSame('Postcode [deleted], total 17000000', $known->scrub('Postcode 700000, total 17000000'));
    }

    public function test_text_values_match_without_case(): void
    {
        $this->assertSame('Mail [deleted]', KnownValues::of(['an@example.com'])->scrub('Mail AN@Example.com'));
    }

    public function test_accented_text_matches_without_case(): void
    {
        $this->assertSame('Giao cho [deleted]', KnownValues::of(['Trần Đức'])->scrub('Giao cho TRẦN ĐỨC'));
    }

    public function test_a_short_number_is_never_a_known_value(): void
    {
        $this->assertSame([], KnownValues::of(['100', '12-34'])->all());
        $this->assertSame('qty 100', KnownValues::of(['100'])->scrub('qty 100'));
    }

    public function test_json_keys_and_numbers_are_kept_and_only_string_values_are_scrubbed(): void
    {
        $json = (string) json_encode(['access_token' => 'tok-1', 'amount' => 100, 'payer' => ['name' => 'Ken Sato']]);

        $out = json_decode(JsonScrubber::scrub($json, [], KnownValues::of(['Ken', 'Ken Sato'])), true);

        $this->assertSame('tok-1', $out['access_token']);
        $this->assertSame(100, $out['amount']);
        $this->assertSame('[deleted]', $out['payer']['name']);
    }

    public function test_json_paths_are_emptied_in_objects_and_in_every_element_of_a_list(): void
    {
        $known = KnownValues::of([]);
        $json = json_encode([['destination' => ['name' => 'A', 'city' => 'X']], ['destination' => ['name' => 'B']]]);

        $out = json_decode(JsonScrubber::scrub((string) $json, ['destination.name'], $known), true);

        $this->assertNull($out[0]['destination']['name']);
        $this->assertSame('X', $out[0]['destination']['city']);
        $this->assertNull($out[1]['destination']['name']);
    }

    public function test_text_that_is_not_json_is_scrubbed_as_free_text(): void
    {
        $this->assertSame('to [deleted]', JsonScrubber::scrub('to Nguyen Van An', ['name'], KnownValues::of(['Nguyen Van An'])));
    }

    public function test_a_declaration_without_a_link_or_without_a_changed_column_is_refused(): void
    {
        $noLink = PersonalDataSource::table('t')->deleteRows();
        $onlyKeep = PersonalDataSource::table('t')->link(SubjectLink::customer('customer_id'))->anonymise(['total' => 'keep']);

        foreach ([$noLink, $onlyKeep] as $source) {
            try {
                $source->assertComplete();
                $this->fail('incomplete declaration accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_identifiers_that_are_not_plain_names_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PersonalDataSource::table('orders; DROP TABLE x');
    }

    public function test_an_unknown_column_rule_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ColumnRule::of('hash');
    }

    public function test_a_manifest_entry_becomes_a_declaration(): void
    {
        $source = ManifestDeclarations::parse([
            'table' => 'product_review', 'link' => ['customer' => 'customer_id'], 'rule' => 'anonymise',
            'columns' => ['author' => 'mark', 'content' => 'scrubText'], 'identity' => ['author'], 'emails' => ['author_email'],
            'export' => ['rating'], 'order' => 30, 'label' => ['en' => 'Reviews'],
        ], 'Plugins/ProductRating');

        $this->assertSame('product_review', $source->table);
        $this->assertSame(RowRule::Anonymise, $source->rowRule());
        $this->assertSame(ColumnRule::SCRUB_TEXT, $source->columns()['content']->kind);
        $this->assertSame(['author_email'], $source->emailColumns());
        $this->assertSame('Plugins/ProductRating', $source->owner());
        $this->assertSame(30, $source->sortOrder());
    }

    public function test_an_extension_cannot_declare_a_table_of_the_platform(): void
    {
        foreach ([['admin_user', []], ['personal_access_tokens', []], ['shop_order', ['shop_order']]] as [$table, $platform]) {
            try {
                ManifestDeclarations::assertNotPlatform($table, $platform);
                $this->fail($table . ' accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($table, $e->getMessage());
            }
        }
        ManifestDeclarations::assertNotPlatform('shop_discount_customer', ['shop_order']);
        $this->addToAssertionCount(1);
    }

    public function test_a_manifest_entry_with_an_unknown_rule_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ManifestDeclarations::parse(['table' => 'x', 'link' => ['customer' => 'customer_id'], 'rule' => 'wipe'], 'Plugins/X');
    }

    public function test_subject_emails_are_trimmed_lowercased_and_unique(): void
    {
        $subject = DataSubject::customer('c-1', [' A@Example.com', 'a@example.com', '', 'not-an-email']);

        $this->assertSame(['a@example.com'], $subject->emails);
        $this->assertTrue(DataSubject::guest('g@example.com')->isGuest());
    }

    public function test_a_customer_subject_needs_a_real_id_and_a_guest_needs_an_email(): void
    {
        foreach ([fn () => DataSubject::customer('0'), fn () => DataSubject::guest('  ')] as $make) {
            try {
                $make();
                $this->fail('subject accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
