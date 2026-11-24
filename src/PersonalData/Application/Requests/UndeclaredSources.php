<?php

namespace GP247\Core\PersonalData\Application\Requests;

use GP247\Core\PersonalData\Application\PersonalDataColumns;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tables of this site's database with columns that look like customer data (email, phone,
 * name, address, IP…) that no package or extension declared — typically a third-party plugin's.
 * Skipped: declared sources, tables a package says hold no customer data, and tables an
 * administrator marked as such (each mark goes into the request history).
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-registry-undeclared-detection, US-personal-data-requests-overdue-alert
 * @aidlc-adr personal-data-requests_undeclared-sources
 */
final class UndeclaredSources
{
    private const CACHE_SECONDS = 600;

    /** Tables never reported: framework tables and the backup / restore copies. */
    private const IGNORED = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    /**
     * @return list<array{table: string, columns: list<string>}> Logical table names (prefix removed).
     */
    public function find(): array
    {
        $known = [];
        // WHY both spellings: a plugin table without the site prefix is listed (and marked) by
        // its raw name, a GP247 table by its logical name.
        foreach ([...array_keys(PersonalDataRegistry::notCustomerTables()), ...PrivacySettings::notCustomerTables()] as $table) {
            $known[GP247_DB_PREFIX . $table] = true;
            $known[(string) $table] = true;
        }
        foreach (PersonalDataRegistry::sources() as $source) {
            $known[$source->physicalTable()] = true;
        }
        foreach (self::IGNORED as $table) {
            $known[$table] = true;
        }
        $out = [];
        foreach ($this->candidates() as $name => $columns) {
            if (isset($known[$name]) || preg_match('/__bk[a-z]$/', $name) === 1) {
                continue;
            }
            $logical = GP247_DB_PREFIX !== '' && str_starts_with($name, GP247_DB_PREFIX) ? substr($name, strlen(GP247_DB_PREFIX)) : $name;
            $out[] = ['table' => $logical, 'columns' => $columns];
        }

        return $out;
    }

    /**
     * Read the database again on the next find() (an administrator asked to check now).
     *
     * @return void
     */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * @return string
     */
    private function cacheKey(): string
    {
        $db = DB::connection(GP247_DB_CONNECTION);

        return 'gp247:pd-undeclared:' . md5($db->getName() . '|' . $db->getDatabaseName());
    }

    /**
     * Tables of this site's database with columns that look like customer data, before the
     * declared ones are taken out. Read once per CACHE_SECONDS — one query on MySQL / MariaDB
     * instead of one per table.
     *
     * @return array<string, list<string>> Physical table name => matching columns (sorted by name).
     */
    private function candidates(): array
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $database = (string) $db->getDatabaseName();

        return Cache::remember($this->cacheKey(), self::CACHE_SECONDS, function () use ($db, $database) {
            $columnsByTable = [];
            if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                // WHY raw: a connection table prefix must not be put in front of information_schema.
                $rows = $db->select('select table_name as t, column_name as c from information_schema.columns where table_schema = ? order by table_name, ordinal_position', [$database]);
                foreach ($rows as $row) {
                    $columnsByTable[(string) $row->t][] = (string) $row->c;
                }
            } else {
                $schema = $db->getSchemaBuilder();
                // WHY the schema argument: on a shared server getTables() may list every database.
                foreach ($schema->getTables($db->getDriverName() === 'sqlite' ? null : $database) as $table) {
                    $columnsByTable[(string) $table['name']] = array_column($schema->getColumns((string) $table['name']), 'name');
                }
            }
            $out = [];
            foreach ($columnsByTable as $table => $columns) {
                $matching = PersonalDataColumns::matching($columns);
                if ($matching !== []) {
                    $out[$table] = $matching;
                }
            }
            ksort($out);

            return $out;
        });
    }

    /**
     * An administrator says a table holds no customer data (or takes that back).
     *
     * @param string                                $table  Logical name.
     * @param bool                                  $marked
     * @param array{type: string, id?: string|null} $actor
     * @return void
     * @throws \InvalidArgumentException When the name is not a plain table name.
     */
    public function mark(string $table, bool $marked, array $actor): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) !== 1) {
            throw new \InvalidArgumentException('Invalid table name');
        }
        $tables = PrivacySettings::notCustomerTables();
        $tables = $marked ? array_values(array_unique([...$tables, $table])) : array_values(array_diff($tables, [$table]));
        PrivacySettings::saveNotCustomerTables($tables);
        app(RequestLog::class)->append(null, $marked ? 'source_excluded' : 'source_included', $actor, null, ['table' => $table]);
    }
}
