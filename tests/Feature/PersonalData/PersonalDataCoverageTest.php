<?php

namespace GP247\Core\Tests\Feature\PersonalData;

use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guard: every table of gp247/core with a column that looks like personal data (email, phone,
 * names, address, IP, browser, customer id) is either declared in the personal data registry
 * or listed below with the reason it is not customer data. Adding such a column without a
 * decision makes this test fail.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-declare-sources, US-personal-data-registry-undeclared-detection
 * @aidlc-adr personal-data-registry_source-declaration
 */
#[Group('personal-data-registry')]
class PersonalDataCoverageTest extends TestCase
{
    /** Column names that suggest personal data. */
    public const PATTERN = \GP247\Core\PersonalData\Application\PersonalDataColumns::PATTERN;

    /** Core tables checked by this guard (logical names; front / shop / extensions guard their own). */
    private const CORE_PREFIXES = ['admin_', 'api_', 'backup_', 'personal_data_', 'personal_access_tokens'];


    public function test_every_core_table_with_personal_looking_columns_is_declared_or_explained(): void
    {
        $declared = array_map(fn ($s) => $s->table, PersonalDataRegistry::sources());
        $missing = [];
        foreach ($this->coreTablesWithPersonalColumns() as $table => $columns) {
            if (!in_array($table, $declared, true) && !array_key_exists($table, \GP247\Core\PersonalData\Application\PersonalDataRegistry::notCustomerTables())) {
                $missing[] = $table . ' (' . implode(', ', $columns) . ')';
            }
        }

        $this->assertSame([], $missing, 'Declare these tables in the personal data registry, or explain here why they hold no customer data.');
    }

    /**
     * @return array<string, list<string>> Logical table => matching columns.
     */
    private function coreTablesWithPersonalColumns(): array
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $schema = $db->getSchemaBuilder();
        $out = [];
        foreach ($schema->getTables() as $table) {
            // WHY: on a shared server getTables() lists every database; only this one counts.
            if (isset($table['schema']) && $table['schema'] !== $db->getDatabaseName()) {
                continue;
            }
            $name = (string) $table['name'];
            if (GP247_DB_PREFIX !== '' && !str_starts_with($name, GP247_DB_PREFIX)) {
                continue;
            }
            $logical = substr($name, strlen(GP247_DB_PREFIX));
            if (!$this->isCore($logical)) {
                continue;
            }
            $matches = preg_grep(self::PATTERN, array_column($schema->getColumns($name), 'name'));
            if ($matches !== []) {
                $out[$logical] = array_values($matches);
            }
        }

        return $out;
    }

    /**
     * @param string $logical
     * @return bool
     */
    private function isCore(string $logical): bool
    {
        foreach (self::CORE_PREFIXES as $prefix) {
            if (str_starts_with($logical, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
