<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clear the share image (admin_store.og_image) that older installs seeded with
 * GP247's bundled sample picture. gp247/core no longer ships that file and the
 * storefront renders no og:image when a store has none, so a store still pointing
 * at it would otherwise share a missing file. Only rows holding exactly the old
 * default value are touched — an image the owner chose is kept. Idempotent.
 *
 * @aidlc-unit installer-deploy
 * @aidlc-story US-DEP-no-default-og-image
 */
return new class extends Migration
{
    /**
     * The values the old DataDefaultSeeder / installers wrote.
     *
     * @var array<int, string>
     */
    private const OLD_DEFAULTS = ['/GP247/Core/images/org.jpg', 'GP247/Core/images/org.jpg'];

    /**
     * Set og_image to NULL where it still is the old bundled default.
     */
    public function up(): void
    {
        $table = GP247_DB_PREFIX . 'admin_store';
        $schema = Schema::connection(GP247_DB_CONNECTION);
        if (!$schema->hasTable($table) || !$schema->hasColumn($table, 'og_image')) {
            return;
        }

        DB::connection(GP247_DB_CONNECTION)->table($table)
            ->whereIn('og_image', self::OLD_DEFAULTS)
            ->update(['og_image' => null]);
    }

    /**
     * Irreversible on purpose: the cleared value was only the old default and the
     * file it named is no longer shipped, so restoring it would point at nothing.
     */
    public function down(): void
    {
    }
};
