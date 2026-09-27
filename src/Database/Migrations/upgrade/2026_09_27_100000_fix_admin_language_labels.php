<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent upgrade for sites installed before these labels were fixed in
 * DataLanguageSeeder:
 *   - seed `admin.error`, the screen-reader text of the tab error marker
 *     (<x-gp247::tabs>), which was rendered as the raw key because it was never seeded;
 *   - correct the English "infomation" spelling of three admin labels.
 *
 * A label is only corrected while it still holds the exact misspelled text, so a
 * wording the site owner already edited is kept; insertOrIgnore keeps an existing
 * `admin.error` row. Runs via gp247:core-update (--path upgrade/).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-multi-vendor-pro-vendor-admin-livewire
 */
return new class extends Migration
{
    /**
     * English labels to correct: code => [misspelled, corrected].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private array $labels = [
        'admin.store.title'           => ['Website infomation', 'Website information'],
        'admin.store.config_info'     => ['Infomation', 'Information'],
        'admin.menu_titles.store_info' => ['Website infomation', 'Website information'],
    ];

    /**
     * @return void
     */
    public function up()
    {
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX.'languages');

        (clone $table)->insertOrIgnore([
            ['code' => 'admin.error', 'text' => 'Error', 'position' => 'admin.common', 'location' => 'en'],
            ['code' => 'admin.error', 'text' => 'Lỗi',   'position' => 'admin.common', 'location' => 'vi'],
        ]);

        foreach ($this->labels as $code => [$wrong, $right]) {
            (clone $table)->where('code', $code)->where('location', 'en')->where('text', $wrong)
                ->update(['text' => $right]);
        }
    }

    /**
     * Restores the previous state. The corrected spelling is only reverted where it
     * still holds the text this migration wrote.
     *
     * @return void
     */
    public function down()
    {
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX.'languages');

        (clone $table)->where('code', 'admin.error')->delete();
        foreach ($this->labels as $code => [$wrong, $right]) {
            (clone $table)->where('code', $code)->where('location', 'en')->where('text', $right)
                ->update(['text' => $wrong]);
        }
    }
};
