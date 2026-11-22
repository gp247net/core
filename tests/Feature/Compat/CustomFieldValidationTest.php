<?php

namespace GP247\Core\Tests\Feature\Compat;

use GP247\Core\Models\AdminCustomField;
use GP247\Core\Models\AdminCustomFieldDetail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use GP247\Core\Tests\TestCase;

/**
 * Coverage for the custom-field typed-validation + mass-assignment hardening (P3 group):
 * server-side rules derived from each field's `option` type (P3a), checkbox/choice
 * membership that keeps comma-bearing junk out of the stored CSV (P3b), and explicit
 * $fillable allow-lists on both EAV models (P3c).
 *
 * gp247_custom_field_validate() reads definitions from the DB, so the integration cases
 * run at the Feature tier against the dev database (DatabaseTransactions rolls back).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-custom-field-hardening
 * @aidlc-adr ADR-compat-foundation-custom-field-integrity
 */
class CustomFieldValidationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A concrete entity type that gp247_custom_field_list can resolve.
     *
     * @return string
     */
    private function entityType(): string
    {
        $tables = gp247_custom_field_get_tables();
        $this->assertNotEmpty($tables, 'Expected at least one gp247_ table for the custom-field type.');

        return (string) $tables[0];
    }

    /**
     * Create a definition row directly for a given type/code/option.
     *
     * @param string $type
     * @param string $code
     * @param string $option
     * @param array  $choices  Option keys/labels for select/radio/checkbox (stored as JSON default).
     * @param bool   $required
     * @return AdminCustomField
     */
    private function makeDefinition(
        string $type,
        string $code,
        string $option = 'text',
        array $choices = [],
        bool $required = false,
    ): AdminCustomField {
        return AdminCustomField::create([
            'type' => $type,
            'code' => $code,
            'name' => 'ZZ ' . $code,
            'option' => $option,
            'default' => $choices ? json_encode($choices) : '',
            'required' => $required ? 1 : 0,
            'status' => 1,
        ]);
    }

    /**
     * P3a — the rule builder maps option types to the expected Laravel rule shape.
     * Pure function, no DB.
     *
     * @return void
     */
    public function test_rule_builder_maps_option_types(): void
    {
        $email = gp247_custom_field_rules((object) ['option' => 'email', 'required' => 1, 'default' => '']);
        $this->assertSame(['required', 'string', 'email', 'max:255'], $email['']);

        $number = gp247_custom_field_rules((object) ['option' => 'number', 'required' => 0, 'default' => '']);
        $this->assertSame(['nullable', 'numeric'], $number['']);

        // Checkbox exposes both the array rule and a per-element wildcard rule.
        $checkbox = gp247_custom_field_rules((object) [
            'option' => 'checkbox',
            'required' => 0,
            'default' => json_encode(['s' => 'S', 'm' => 'M']),
        ]);
        $this->assertSame(['nullable', 'array'], $checkbox['']);
        $this->assertArrayHasKey('.*', $checkbox);
    }

    /**
     * P3a — a required typed field accepts a well-formed value and rejects a malformed one.
     *
     * @return void
     */
    public function test_typed_rules_accept_valid_reject_invalid(): void
    {
        $type = $this->entityType();
        $this->makeDefinition($type, 'zz_mail', 'email', required: true);

        $rules = gp247_custom_field_validate([], $type);
        $this->assertArrayHasKey('fields.zz_mail', $rules);

        $this->assertTrue(
            Validator::make(['fields' => ['zz_mail' => 'buyer@example.com']], $rules)->passes(),
            'A valid email must pass.',
        );
        $this->assertTrue(
            Validator::make(['fields' => ['zz_mail' => 'not-an-email']], $rules)->fails(),
            'A malformed email must be rejected.',
        );
        $this->assertTrue(
            Validator::make(['fields' => []], $rules)->fails(),
            'A required field left blank must be rejected.',
        );
    }

    /**
     * P3a — an optional typed field left blank passes (nullable-friendly).
     *
     * @return void
     */
    public function test_optional_typed_field_blank_passes(): void
    {
        $type = $this->entityType();
        $this->makeDefinition($type, 'zz_site', 'url', required: false);

        $rules = gp247_custom_field_validate([], $type);

        $this->assertTrue(
            Validator::make(['fields' => ['zz_site' => '']], $rules)->passes(),
            'An optional url left blank must pass.',
        );
        $this->assertTrue(
            Validator::make(['fields' => ['zz_site' => 'http://ok.example']], $rules)->passes(),
            'An optional url with a valid value must pass.',
        );
        $this->assertTrue(
            Validator::make(['fields' => ['zz_site' => 'javascript:alert(1)']], $rules)->fails(),
            'A non-url value must be rejected even when the field is optional.',
        );
    }

    /**
     * P3a/P3b — select membership: only declared option keys are accepted.
     *
     * @return void
     */
    public function test_select_membership_enforced(): void
    {
        $type = $this->entityType();
        $this->makeDefinition($type, 'zz_color', 'select', ['red' => 'Red', 'green' => 'Green'], true);

        $rules = gp247_custom_field_validate([], $type);

        $this->assertTrue(
            Validator::make(['fields' => ['zz_color' => 'red']], $rules)->passes(),
            'A declared option key must pass.',
        );
        $this->assertTrue(
            Validator::make(['fields' => ['zz_color' => 'purple']], $rules)->fails(),
            'An undeclared option value must be rejected.',
        );
    }

    /**
     * P3b — checkbox membership keeps comma-bearing / rogue values out of the CSV that
     * gp247_custom_field_update() stores via implode(',', ...).
     *
     * @return void
     */
    public function test_checkbox_rejects_rogue_and_comma_values(): void
    {
        $type = $this->entityType();
        $this->makeDefinition($type, 'zz_size', 'checkbox', ['s' => 'S', 'm' => 'M', 'l' => 'L'], false);

        $rules = gp247_custom_field_validate([], $type);
        $this->assertArrayHasKey('fields.zz_size.*', $rules);

        $this->assertTrue(
            Validator::make(['fields' => ['zz_size' => ['s', 'm']]], $rules)->passes(),
            'A subset of declared keys must pass.',
        );
        // A value carrying a comma is by construction not a declared key, so Rule::in blocks
        // it — this is what prevents CSV contamination of the stored checkbox value.
        $this->assertTrue(
            Validator::make(['fields' => ['zz_size' => ['s', 'l,injected']]], $rules)->fails(),
            'A comma-bearing / undeclared checkbox value must be rejected.',
        );
        // Optional checkbox with nothing selected passes.
        $this->assertTrue(
            Validator::make(['fields' => []], $rules)->passes(),
            'An optional checkbox with no selection must pass.',
        );
    }

    /**
     * P3c — mass assignment respects the explicit $fillable allow-list: declared columns
     * are set, and a spoofed non-fillable key (id) is ignored (UuidTrait owns the id).
     *
     * @return void
     */
    public function test_fillable_allows_declared_columns_and_ignores_spoofed_id(): void
    {
        $type = $this->entityType();

        $field = AdminCustomField::create([
            'id' => 'spoofed-id-should-be-ignored',
            'type' => $type,
            'code' => 'zz_fillable',
            'name' => 'ZZ Fillable',
            'option' => 'text',
            'default' => '',
            'required' => 0,
            'status' => 1,
        ]);

        $this->assertNotSame('spoofed-id-should-be-ignored', (string) $field->id, 'A spoofed id must not be mass-assigned.');
        $this->assertSame('zz_fillable', $field->code);

        $detail = AdminCustomFieldDetail::create([
            'custom_field_id' => $field->id,
            'rel_id' => 'zz-rel-' . uniqid(),
            'text' => 'value',
        ]);

        $this->assertSame('value', $detail->text);
        $this->assertNotEmpty((string) $detail->id, 'The detail row must receive a generated id.');
    }
}
