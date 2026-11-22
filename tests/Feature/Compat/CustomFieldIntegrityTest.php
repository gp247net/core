<?php

namespace GP247\Core\Tests\Feature\Compat;

use GP247\Core\AdminShell\Http\Livewire\CustomFieldForm;
use GP247\Core\Models\AdminCustomField;
use GP247\Core\Models\AdminCustomFieldDetail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * Integration coverage for the custom-field (EAV) integrity hardening (P1 group):
 * atomic replace-on-write (P1a), cascade delete of detail rows when a definition is
 * removed (P1b), and application-layer uniqueness of (type, code) (P1c).
 *
 * These are DB-backed behaviours, so they run at the Feature tier against the real
 * dev database (DatabaseTransactions rolls everything back).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-custom-field-hardening
 * @aidlc-adr ADR-compat-foundation-custom-field-integrity
 */
class CustomFieldIntegrityTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * A concrete entity type that passes the gp247_custom_field_update guard
     * (must be one of the real gp247_-prefixed tables).
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
     * Create a definition row directly (bypassing the Livewire form) for a given type/code.
     *
     * @param string $type
     * @param string $code
     * @return AdminCustomField
     */
    private function makeDefinition(string $type, string $code): AdminCustomField
    {
        return AdminCustomField::create([
            'type' => $type,
            'code' => $code,
            'name' => 'ZZ ' . $code,
            'option' => 'text',
            'default' => '',
            'required' => 0,
            'status' => 1,
        ]);
    }

    /**
     * P1a — happy path: the destructive replace keeps exactly the new values,
     * dropping any previous detail rows for the same entity/type.
     *
     * @return void
     */
    public function test_update_replaces_detail_values_atomically(): void
    {
        $type = $this->entityType();
        $relId = 'zz-rel-' . uniqid();

        $fieldA = $this->makeDefinition($type, 'zz_color');
        $fieldB = $this->makeDefinition($type, 'zz_size');

        // Seed an initial (stale) value that must be wiped by the replace.
        AdminCustomFieldDetail::create([
            'custom_field_id' => $fieldA->id,
            'rel_id' => $relId,
            'text' => 'old-red',
        ]);

        gp247_custom_field_update(
            ['zz_color' => 'blue', 'zz_size' => 'large'],
            $relId,
            $type,
        );

        $values = AdminCustomFieldDetail::whereIn('custom_field_id', [$fieldA->id, $fieldB->id])
            ->where('rel_id', $relId)
            ->pluck('text', 'custom_field_id');

        $this->assertSame('blue', $values[$fieldA->id]);
        $this->assertSame('large', $values[$fieldB->id]);
        $this->assertCount(2, $values, 'Stale detail rows must be removed by the replace.');
    }

    /**
     * P1a — atomicity: if an insert fails mid-replace, the whole operation rolls back
     * and the previous values remain intact (no partially-wiped state).
     *
     * @return void
     */
    public function test_update_rolls_back_on_mid_write_failure(): void
    {
        $type = $this->entityType();
        $relId = 'zz-rel-' . uniqid();

        $fieldA = $this->makeDefinition($type, 'zz_color');

        AdminCustomFieldDetail::create([
            'custom_field_id' => $fieldA->id,
            'rel_id' => $relId,
            'text' => 'old-red',
        ]);

        // WHY: inject a failure on the re-insert so we exercise the delete-then-insert
        // window. The listener stays registered for the process but is neutered via the
        // by-reference flag after this test, so later tests see it as a no-op.
        $shouldThrow = true;
        AdminCustomFieldDetail::creating(function () use (&$shouldThrow): void {
            if ($shouldThrow) {
                throw new \RuntimeException('injected mid-write failure');
            }
        });

        try {
            $threw = false;
            try {
                gp247_custom_field_update(['zz_color' => 'blue'], $relId, $type);
            } catch (\RuntimeException $e) {
                $threw = true;
            }

            $this->assertTrue($threw, 'The injected failure should propagate out of the update.');

            $surviving = AdminCustomFieldDetail::where('custom_field_id', $fieldA->id)
                ->where('rel_id', $relId)
                ->pluck('text');

            $this->assertSame(['old-red'], $surviving->all(), 'The prior value must survive a rolled-back write.');
        } finally {
            $shouldThrow = false;
        }
    }

    /**
     * P1b — deleting a definition cascades to its detail rows (no orphaned EAV data).
     *
     * @return void
     */
    public function test_deleting_definition_cascades_detail_rows(): void
    {
        $type = $this->entityType();
        $relId = 'zz-rel-' . uniqid();

        $field = $this->makeDefinition($type, 'zz_note');
        AdminCustomFieldDetail::create([
            'custom_field_id' => $field->id,
            'rel_id' => $relId,
            'text' => 'keep-until-deleted',
        ]);

        $this->assertTrue(
            AdminCustomFieldDetail::where('custom_field_id', $field->id)->exists(),
            'Detail row should exist before the definition is deleted.',
        );

        $field->delete();

        $this->assertFalse(
            AdminCustomFieldDetail::where('custom_field_id', $field->id)->exists(),
            'Detail rows must be removed when the definition is deleted (cascade).',
        );
    }

    /**
     * P1c — a duplicate (type, code) is rejected by the form's uniqueness rule.
     *
     * @return void
     */
    public function test_duplicate_code_per_type_is_rejected(): void
    {
        $this->asUser(slugs: ['admin_custom_field']);

        $type = $this->entityType();
        $this->makeDefinition($type, 'zz_dup');

        Livewire::test(CustomFieldForm::class)
            ->set('form.type', $type)
            ->set('form.name', 'ZZ Duplicate')
            ->set('form.code', 'zz_dup')
            ->call('save')
            ->assertHasErrors(['form.code']);

        $this->assertSame(
            1,
            AdminCustomField::where('type', $type)->where('code', 'zz_dup')->count(),
            'The duplicate definition must not be persisted.',
        );
    }

    /**
     * P1c — editing a definition without changing its code does not false-positive
     * on itself.
     *
     * @return void
     */
    public function test_editing_same_definition_keeps_its_code(): void
    {
        $this->asUser(slugs: ['admin_custom_field']);

        $type = $this->entityType();
        $field = $this->makeDefinition($type, 'zz_keep');

        Livewire::test(CustomFieldForm::class, ['id' => (string) $field->id])
            ->set('form.name', 'ZZ Keep Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            'ZZ Keep Renamed',
            AdminCustomField::find($field->id)->name,
            'Editing the same record with its own code must be allowed.',
        );
    }

    /**
     * P1c (DB-enforced) — the composite unique index (type, code) rejects a duplicate at the
     * database layer, closing the paths the CustomFieldForm rule cannot see (seeders, imports,
     * direct create()). Safe to enforce in the create migration because GP247 2.0 has no
     * in-place 1.x upgrade, so every install starts from an empty table.
     *
     * @return void
     */
    public function test_duplicate_code_per_type_is_rejected_at_db_level(): void
    {
        $type = $this->entityType();
        $this->makeDefinition($type, 'zz_db_dup');

        // A second definition with the same (type, code) must violate the unique index,
        // bypassing the application-layer form rule entirely.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeDefinition($type, 'zz_db_dup');
    }
}
