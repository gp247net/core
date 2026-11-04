<?php

namespace GP247\Core\Backup\Domain;

/**
 * A business group of tables that a selective backup or restore handles together
 * (e.g. "orders", "catalog", or the tables of one extension).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-data-groups
 * @aidlc-adr backup-engine_data-group-registry
 */
final class DataGroup
{
    /**
     * @param string       $key    Stable group key ("orders", "ext.plugins.ProductRating").
     * @param string       $label  Language key, or the extension name for extension groups.
     * @param list<string> $tables Existing tables of the group, without GP247_DB_PREFIX.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $tables,
    ) {
    }
}
