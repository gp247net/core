<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent upgrade: seed the plugin-manager configCode filter labels (6 group names +
 * the filter bar strings) for sites installed before US-PLG-config-code-filter.
 *
 * WHY: the plugin-manager screen filter chips (Payment/Shipping/Promotion/Marketing/
 * Security/Other) render their titles via gp247_language_render('admin.menu_titles.
 * plugin_group_*') plus 'admin.extension.filter_*', which read the gp247_languages table
 * first. Fresh installs get these from DataLanguageSeeder; existing installs need this
 * backfill so the chips are not shown as raw keys. insertOrIgnore keeps any text a site
 * owner already edited via the Language manager.
 *
 * Runs via gp247:core-update (--path upgrade/), never the create-tables migration.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-config-code-filter
 * @aidlc-adr plugin-manager_config-code-filter
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $rows = [
            ['code' => 'admin.menu_titles.plugin_group_payment',   'text' => 'Phương thức thanh toán', 'position' => 'admin.menu_titles', 'location' => 'vi'],
            ['code' => 'admin.menu_titles.plugin_group_payment',   'text' => 'Payment methods',        'position' => 'admin.menu_titles', 'location' => 'en'],
            ['code' => 'admin.menu_titles.plugin_group_shipping',  'text' => 'Phương thức vận chuyển', 'position' => 'admin.menu_titles', 'location' => 'vi'],
            ['code' => 'admin.menu_titles.plugin_group_shipping',  'text' => 'Shipping methods',       'position' => 'admin.menu_titles', 'location' => 'en'],
            ['code' => 'admin.menu_titles.plugin_group_promotion', 'text' => 'Khuyến mãi',             'position' => 'admin.menu_titles', 'location' => 'vi'],
            ['code' => 'admin.menu_titles.plugin_group_promotion', 'text' => 'Promotion',              'position' => 'admin.menu_titles', 'location' => 'en'],
            ['code' => 'admin.menu_titles.plugin_group_marketing', 'text' => 'Marketing',              'position' => 'admin.menu_titles', 'location' => 'vi'],
            ['code' => 'admin.menu_titles.plugin_group_marketing', 'text' => 'Marketing',              'position' => 'admin.menu_titles', 'location' => 'en'],
            ['code' => 'admin.menu_titles.plugin_group_content',   'text' => 'Nội dung',               'position' => 'admin.menu_titles', 'location' => 'vi'],
            ['code' => 'admin.menu_titles.plugin_group_content',   'text' => 'Content',                'position' => 'admin.menu_titles', 'location' => 'en'],
            ['code' => 'admin.menu_titles.plugin_group_business',  'text' => 'Kinh doanh',             'position' => 'admin.menu_titles', 'location' => 'vi'],
            ['code' => 'admin.menu_titles.plugin_group_business',  'text' => 'Business',               'position' => 'admin.menu_titles', 'location' => 'en'],
            ['code' => 'admin.menu_titles.plugin_group_security',  'text' => 'Bảo mật',                'position' => 'admin.menu_titles', 'location' => 'vi'],
            ['code' => 'admin.menu_titles.plugin_group_security',  'text' => 'Security',               'position' => 'admin.menu_titles', 'location' => 'en'],
            ['code' => 'admin.menu_titles.plugin_group_other',     'text' => 'Khác',                   'position' => 'admin.menu_titles', 'location' => 'vi'],
            ['code' => 'admin.menu_titles.plugin_group_other',     'text' => 'Other',                  'position' => 'admin.menu_titles', 'location' => 'en'],
            ['code' => 'admin.extension.filter_by_group',          'text' => 'Lọc theo nhóm:',         'position' => 'admin.extension',   'location' => 'vi'],
            ['code' => 'admin.extension.filter_by_group',          'text' => 'Filter by group:',       'position' => 'admin.extension',   'location' => 'en'],
            ['code' => 'admin.extension.filter_clear',             'text' => 'Bỏ lọc',                 'position' => 'admin.extension',   'location' => 'vi'],
            ['code' => 'admin.extension.filter_clear',             'text' => 'Clear filter',           'position' => 'admin.extension',   'location' => 'en'],
        ];

        DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX.'languages')
            ->insertOrIgnore($rows);
    }

    /**
     * @return void
     */
    public function down()
    {
        DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX.'languages')
            ->whereIn('code', [
                'admin.menu_titles.plugin_group_payment',
                'admin.menu_titles.plugin_group_shipping',
                'admin.menu_titles.plugin_group_promotion',
                'admin.menu_titles.plugin_group_marketing',
                'admin.menu_titles.plugin_group_content',
                'admin.menu_titles.plugin_group_business',
                'admin.menu_titles.plugin_group_security',
                'admin.menu_titles.plugin_group_other',
                'admin.extension.filter_by_group',
                'admin.extension.filter_clear',
            ])
            ->delete();
    }
};
