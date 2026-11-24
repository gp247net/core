<?php

namespace GP247\Core\PersonalData\Application;

use GP247\Core\Backup\Application\DataGroupRegistry;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;

/**
 * Reads the "personalData" key of every local extension's gp247.json:
 *
 *   "personalData": [
 *     { "table": "product_review", "key": "id",
 *       "link": {"customer": "customer_id"},            // or a list of links
 *       "rule": "delete",                                // delete | anonymise | retain
 *       "columns": {"author_name": "mark", "content": "scrubText"},
 *       "identity": ["author_name"], "emails": ["author_email"],
 *       "export": ["product_id", "rating", "content", "created_at"],
 *       "order": 30,
 *       "label": {"en": "Product reviews", "vi": "Đánh giá sản phẩm", "ja": "商品レビュー"} }
 *   ]
 *
 * A broken entry is reported and skipped — one extension's typo must not stop the site.
 * Extensions that are disabled or uninstalled (their tables kept) are read too: their data must
 * not escape an erasure. An entry may not name a table of the platform: those are declared by
 * core / front / shop, and a manifest pointing at them (e.g. admin accounts by email) could
 * erase unrelated data.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-declare-sources
 * @aidlc-adr personal-data-registry_source-declaration
 */
final class ManifestDeclarations
{
    /** Table name prefixes reserved to the platform. */
    private const RESERVED_PREFIXES = ['admin_', 'api_', 'personal_data_', 'personal_access_tokens'];

    /**
     * @return array<string, PersonalDataSource> Keyed by table.
     */
    public static function read(): array
    {
        $out = [];
        $platform = self::platformTables();
        foreach (DataGroupRegistry::extensionManifests() as $id => $manifest) {
            foreach ((array) ($manifest['personalData'] ?? []) as $entry) {
                try {
                    $source = self::parse(is_array($entry) ? $entry : [], (string) $id);
                    self::assertNotPlatform($source->table, $platform);
                    $out[$source->table] = $source;
                } catch (\Throwable $e) {
                    if (function_exists('gp247_report')) {
                        gp247_report('Invalid personalData entry in ' . $id . ': ' . $e->getMessage());
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param string       $table
     * @param list<string> $platform Tables declared by the platform for backups.
     * @return void
     * @throws \InvalidArgumentException When the table belongs to the platform.
     */
    public static function assertNotPlatform(string $table, array $platform): void
    {
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                throw new \InvalidArgumentException('Table reserved to the platform: ' . $table);
            }
        }
        if (in_array($table, $platform, true)) {
            throw new \InvalidArgumentException('Table reserved to the platform: ' . $table);
        }
    }

    /**
     * @return list<string> Tables core / front / shop declared in their backup groups.
     */
    private static function platformTables(): array
    {
        $tables = (array) config('gp247-config.backup.excluded_tables', []);
        foreach ((array) config('gp247-config.backup.data_groups', []) as $group) {
            $tables = array_merge($tables, (array) ($group['tables'] ?? []));
        }

        return array_values(array_unique(array_map('strval', $tables)));
    }

    /**
     * @param array<string, mixed> $entry
     * @param string               $owner Extension id (Plugins/Key).
     * @return PersonalDataSource
     * @throws \InvalidArgumentException
     */
    public static function parse(array $entry, string $owner): PersonalDataSource
    {
        $source = PersonalDataSource::table((string) ($entry['table'] ?? ''), (string) ($entry['key'] ?? 'id'));
        $links = $entry['link'] ?? null;
        $links = is_array($links) && array_is_list($links) ? $links : [$links];
        foreach ($links as $link) {
            $source = $source->link(SubjectLink::fromArray(is_array($link) ? $link : []));
        }
        $source = match ((string) ($entry['rule'] ?? '')) {
            'delete' => $source->deleteRows(),
            'anonymise' => $source->anonymise((array) ($entry['columns'] ?? [])),
            'retain' => $source->retain(),
            default => throw new \InvalidArgumentException('Unknown rule'),
        };
        $source = $source
            ->identity(array_map('strval', (array) ($entry['identity'] ?? [])), array_map('strval', (array) ($entry['emails'] ?? [])))
            ->export(array_map('strval', (array) ($entry['export'] ?? [])))
            ->order((int) ($entry['order'] ?? 50))
            ->describe(is_array($entry['label'] ?? null) ? array_map('strval', $entry['label']) : (string) ($entry['label'] ?? ''), $owner);
        $source->assertComplete();

        return $source;
    }
}
