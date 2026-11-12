<?php

namespace GP247\Core\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gives back, once, the text that gp247_clean() HTML-encoded on save before core 3.12, so a
 * running site ends up with text stored as typed (US-CMP-text-stored-as-typed, decision D2:
 * run automatically by gp247:update through each package's upgrade migration).
 *
 * - Exact inverse of gp247_clean(): htmlspecialchars_decode(ENT_COMPAT) — only &amp; &lt;
 *   &gt; &quot; come back; anything a person typed as an entity (stored as &amp;copy;) comes
 *   back as they typed it.
 * - Explicit table => column lists; only rows holding one of those four entities are read
 *   and each is updated by its primary key, in chunks.
 * - One-time flag in admin_config (GLOBAL): a second run changes nothing. A failure before
 *   the end leaves the flag unset; running again is safe for rows not yet decoded.
 *
 * Data-changing (rule data-safety §C): the caller's release notes ask for a backup before
 * gp247:update. Irreversible on purpose — re-encoding would recreate the bug.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-text-stored-as-typed
 * @aidlc-adr compat-foundation_text-stored-as-typed
 */
final class StoredTextDecoder
{
    public const CHUNK = 500;

    private const ENTITIES = ['&amp;', '&lt;', '&gt;', '&quot;'];

    /**
     * Decode the listed columns unless the flag says it was already done.
     *
     * @param string $flag admin_config key marking the run as done (max 50 chars).
     * @param array<string, array<int, string>> $columns Table without prefix => text columns.
     * @param array<string, callable> $constraints Table => fn($query): void narrowing the rows.
     * @param callable|null $keep fn(string $table, string $column, string $decoded): bool — true keeps the stored value.
     * @return array<string, int> Table => rows changed (empty when the flag was already set).
     */
    public static function run(string $flag, array $columns, array $constraints = [], ?callable $keep = null): array
    {
        if (self::hasRun($flag)) {
            return [];
        }

        $changed = [];
        foreach ($columns as $table => $wanted) {
            $changed[$table] = self::decodeTable(GP247_DB_PREFIX . $table, $wanted, $constraints[$table] ?? null, $keep, $table);
        }
        self::markDone($flag);
        Log::info('gp247: stored text decoded', ['flag' => $flag, 'rows' => array_filter($changed)]);

        return $changed;
    }

    /**
     * Whether the decode marked by $flag already ran on this site.
     *
     * @param string $flag
     * @return bool
     */
    public static function hasRun(string $flag): bool
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('key', $flag)
            ->where('store_id', self::globalStore())
            ->where('value', '1')
            ->exists();
    }

    /**
     * Exact inverse of gp247_clean() on one string.
     *
     * @param string $value
     * @return string
     */
    public static function decode(string $value): string
    {
        return htmlspecialchars_decode($value, ENT_COMPAT | ENT_HTML401);
    }

    /**
     * @param string $table Full table name.
     * @param array<int, string> $wanted
     * @param callable|null $constrain
     * @param callable|null $keep
     * @param string $shortName
     * @return int Rows changed.
     */
    private static function decodeTable(string $table, array $wanted, ?callable $constrain, ?callable $keep, string $shortName): int
    {
        $schema = DB::connection(GP247_DB_CONNECTION)->getSchemaBuilder();
        if (!$schema->hasTable($table)) {
            return 0;
        }
        // WHY: a site may not have every column (older schema, column added by a later release).
        $existing = $schema->getColumnListing($table);
        $cols = array_values(array_intersect($wanted, $existing));
        $key = self::primaryKey($table, $existing);
        if ($cols === [] || $key === []) {
            return 0;
        }

        $query = DB::connection(GP247_DB_CONNECTION)->table($table)
            ->select(array_unique(array_merge($key, $cols)))
            ->where(function ($q) use ($cols) {
                foreach ($cols as $col) {
                    foreach (self::ENTITIES as $entity) {
                        $q->orWhere($col, 'like', '%' . $entity . '%');
                    }
                }
            });
        if ($constrain !== null) {
            $constrain($query);
        }

        $changed = 0;
        $process = function ($rows) use ($table, $key, $cols, $keep, $shortName, &$changed) {
            foreach ($rows as $row) {
                $update = [];
                foreach ($cols as $col) {
                    $value = $row->{$col};
                    if (!is_string($value) || $value === '') {
                        continue;
                    }
                    $decoded = self::decode($value);
                    if ($decoded !== $value && !($keep !== null && $keep($shortName, $col, $decoded))) {
                        $update[$col] = $decoded;
                    }
                }
                if ($update === []) {
                    continue;
                }
                $where = DB::connection(GP247_DB_CONNECTION)->table($table);
                foreach ($key as $k) {
                    $where->where($k, $row->{$k});
                }
                $where->update($update);
                $changed++;
            }
        };

        // WHY: the rows being changed are the rows the filter selects, so an offset-based chunk
        // would skip rows. A single key pages by "key > last"; a composite key (description
        // tables) reads the matching rows first — only rows holding an entity, not the table.
        if (count($key) === 1) {
            $query->chunkById(self::CHUNK, $process, $key[0]);
        } else {
            foreach ($query->get()->chunk(self::CHUNK) as $rows) {
                $process($rows);
            }
        }

        return $changed;
    }

    /**
     * Columns that identify one row: 'id' when present, else the primary key, else the first
     * unique index (description tables are keyed by a unique (owner_id, lang) without a
     * primary key on some installs).
     *
     * @param string $table
     * @param array<int, string> $existing
     * @return array<int, string>
     */
    private static function primaryKey(string $table, array $existing): array
    {
        if (in_array('id', $existing, true)) {
            return ['id'];
        }
        $indexes = DB::connection(GP247_DB_CONNECTION)->getSchemaBuilder()->getIndexes($table);
        foreach (['primary', 'unique'] as $kind) {
            foreach ($indexes as $index) {
                if (!empty($index[$kind])) {
                    return array_values($index['columns']);
                }
            }
        }

        return [];
    }

    /**
     * @param string $flag
     * @return void
     */
    private static function markDone(string $flag): void
    {
        $db = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $updated = $db->clone()->where('key', $flag)->where('store_id', self::globalStore())->update(['value' => '1']);
        if ($updated === 0) {
            $db->insert([
                'group' => 'global',
                'code' => 'system',
                'key' => $flag,
                'value' => '1',
                'security' => 0,
                'store_id' => self::globalStore(),
                'sort' => 0,
                'detail' => '',
            ]);
        }
    }

    /**
     * @return string
     */
    private static function globalStore(): string
    {
        return defined('GP247_STORE_ID_GLOBAL') ? (string) GP247_STORE_ID_GLOBAL : '0';
    }
}
