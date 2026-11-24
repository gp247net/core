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
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-declare-sources
 * @aidlc-adr personal-data-registry_source-declaration
 */
final class ManifestDeclarations
{
    /**
     * @return array<string, PersonalDataSource> Keyed by table.
     */
    public static function read(): array
    {
        $out = [];
        foreach (DataGroupRegistry::extensionManifests() as $id => $manifest) {
            foreach ((array) ($manifest['personalData'] ?? []) as $entry) {
                try {
                    $source = self::parse(is_array($entry) ? $entry : [], (string) $id);
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
