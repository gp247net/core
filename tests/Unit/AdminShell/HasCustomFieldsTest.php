<?php

namespace GP247\Core\Tests\Unit\AdminShell;

use GP247\Core\AdminShell\Infrastructure\HasCustomFields;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the reusable custom-fields trait (D0). Covers the
 * database-independent state logic: empty-state init, loading existing values
 * (incl. checkbox splitting), required-field rules and the persist payload.
 * The DB query (customFieldValues) is stubbed out.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-004
 * @aidlc-adr ADR-005, ADR-007
 */
class HasCustomFieldsTest extends TestCase
{
    /**
     * Build a stub component using the trait with fixed field definitions and
     * a stubbed value source, exposing the protected helpers.
     *
     * @param array<int, array<string, mixed>> $defs
     * @param array<string, string> $values
     * @return object
     */
    private function stub(array $defs, array $values = []): object
    {
        return new class($defs, $values) {
            use HasCustomFields;

            /**
             * @param array<int, array<string, mixed>> $defs
             * @param array<string, string> $values
             */
            public function __construct(private array $defs, private array $values)
            {
            }

            protected function customFieldType(): string
            {
                return 'shop_customer';
            }

            protected function customFieldDefs(): iterable
            {
                return array_map(static fn (array $d): object => (object) $d, $this->defs);
            }

            protected function customFieldValues($relId): array
            {
                return $this->values;
            }

            public function callInit(): void
            {
                $this->initCustomFields();
            }

            public function callLoad($relId): void
            {
                $this->loadCustomFields($relId);
            }

            /** @return array<string, mixed> */
            public function callRules(): array
            {
                return $this->customFieldRules();
            }

            /** @return array<string, mixed> */
            public function callPayload(): array
            {
                return $this->customFieldsPayload();
            }
        };
    }

    public function test_init_sets_empty_values_with_array_for_checkbox(): void
    {
        $stub = $this->stub([
            ['code' => 'gender', 'option' => 'text', 'required' => 0],
            ['code' => 'hobbies', 'option' => 'checkbox', 'required' => 0],
        ]);
        $stub->callInit();

        $this->assertSame('', $stub->customFields['gender']);
        $this->assertSame([], $stub->customFields['hobbies']);
    }

    public function test_load_maps_values_and_splits_checkbox(): void
    {
        $stub = $this->stub(
            [
                ['code' => 'gender', 'option' => 'text', 'required' => 0],
                ['code' => 'hobbies', 'option' => 'checkbox', 'required' => 0],
            ],
            ['gender' => 'male', 'hobbies' => 'reading,coding'],
        );
        $stub->callLoad('CUS-1');

        $this->assertSame('male', $stub->customFields['gender']);
        $this->assertSame(['reading', 'coding'], $stub->customFields['hobbies']);
    }

    public function test_load_defaults_missing_values_to_empty(): void
    {
        $stub = $this->stub([
            ['code' => 'gender', 'option' => 'text', 'required' => 0],
            ['code' => 'hobbies', 'option' => 'checkbox', 'required' => 0],
        ], []);
        $stub->callLoad('CUS-1');

        $this->assertSame('', $stub->customFields['gender']);
        $this->assertSame([], $stub->customFields['hobbies']);
    }

    public function test_rules_only_for_required_fields(): void
    {
        $stub = $this->stub([
            ['code' => 'gender', 'option' => 'text', 'required' => 1],
            ['code' => 'note', 'option' => 'text', 'required' => 0],
        ]);

        $rules = $stub->callRules();
        $this->assertArrayHasKey('customFields.gender', $rules);
        $this->assertArrayNotHasKey('customFields.note', $rules);
    }

    public function test_payload_returns_current_values(): void
    {
        $stub = $this->stub([['code' => 'gender', 'option' => 'text', 'required' => 0]]);
        $stub->callInit();
        $stub->customFields['gender'] = 'female';

        $this->assertSame(['gender' => 'female'], $stub->callPayload());
    }
}
