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
     * @param list<string> $raw    Those of $tables that exist only without the prefix (real name = listed name).
     * @param list<string> $help   Language keys describing the group in business words, one per package
     *                             that adds tables to it, in declaration order (mod 20261109T104713).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $tables,
        public readonly array $raw = [],
        public readonly array $help = [],
    ) {
    }

    /**
     * @param string $table Table of this group.
     * @return string Real table name in the database.
     */
    public function realName(string $table): string
    {
        return in_array($table, $this->raw, true) ? $table : GP247_DB_PREFIX . $table;
    }
}
