<?php

namespace GP247\Core\Tests\Feature\SecurityHeaders;

use GP247\Core\Database\Seeders\DataDefaultSeeder;
use GP247\Core\Database\Seeders\DataLanguageSeeder;
use GP247\Core\SecurityHeaders\Application\SecurityHeaderSettings;
use GP247\Core\SecurityHeaders\Infrastructure\SecurityHeadersLanguage;
use GP247\Core\SecurityHeaders\Infrastructure\SecurityHeadersMenu;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Tests\Feature\SecurityHeaders\Support\ConfiguresSecurityHeaders;
use GP247\Core\Tests\TestCase;

/**
 * A fresh install and an update (`gp247:update` re-runs the core seeders) give the same
 * menu, settings and texts, never twice, and never undo a choice the owner made.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-install-lifecycle
 * @aidlc-adr security-headers_config-hsts-and-kill-switch
 */
#[Group('security-headers')]
class SecurityHeadersInstallLifecycleTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresSecurityHeaders;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetConfigMemo();
    }

    /**
     * Rows of the settings of this Unit.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function settingRows()
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('store_id', (string) GP247_STORE_ID_GLOBAL)
            ->where('code', SecurityHeaderSettings::CODE);
    }

    public function test_updating_core_adds_the_menu_and_the_settings_once_even_when_run_again(): void
    {
        (new DataDefaultSeeder())->run();
        (new DataDefaultSeeder())->run();

        $menu = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_menu')->where('uri', SecurityHeadersMenu::URI);
        $this->assertSame(1, $menu->count(), 'one menu item');
        $this->assertSame(count(SecurityHeaderSettings::defaults()), $this->settingRows()->count(), 'one row per setting');
    }

    /**
     * The menu item has its own icon (a globe), not the shield of the Security group and of
     * System alerts.
     */
    public function test_the_menu_item_uses_the_globe_icon(): void
    {
        $this->menuRow()->delete();

        (new DataDefaultSeeder())->run();

        $this->assertSame('fas fa-globe', $this->menuRow()->value('icon'));
    }

    /**
     * A site updated to core 3.16.0 still has the shield icon: updating core switches it to
     * the globe.
     */
    public function test_updating_core_replaces_the_old_shield_icon(): void
    {
        (new DataDefaultSeeder())->run();
        $this->menuRow()->update(['icon' => 'fas fa-shield-alt']);

        (new DataDefaultSeeder())->run();

        $this->assertSame('fas fa-globe', $this->menuRow()->value('icon'));
    }

    /**
     * An icon the owner picked on the Menu screen is kept.
     */
    public function test_updating_core_keeps_an_icon_the_owner_picked(): void
    {
        (new DataDefaultSeeder())->run();
        $this->menuRow()->update(['icon' => 'fas fa-lock']);

        (new DataDefaultSeeder())->run();

        $this->assertSame('fas fa-lock', $this->menuRow()->value('icon'));
    }

    /**
     * The Security headers menu row.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function menuRow()
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_menu')->where('uri', SecurityHeadersMenu::URI);
    }

    public function test_updating_core_keeps_a_header_the_owner_switched_off(): void
    {
        $this->setSecuritySettings([SecurityHeaderSettings::FRAME => 0]);

        (new DataDefaultSeeder())->run();
        $this->forgetConfigMemo();

        $this->assertSame(0, SecurityHeaderSettings::values()[SecurityHeaderSettings::FRAME]);
    }

    public function test_a_site_that_never_had_the_settings_gets_the_four_headers_on_and_hsts_off(): void
    {
        $this->settingRows()->delete();

        (new DataDefaultSeeder())->run();
        $this->forgetConfigMemo();

        $values = SecurityHeaderSettings::values();
        $this->assertSame(1, $values[SecurityHeaderSettings::FRAME]);
        $this->assertSame(1, $values[SecurityHeaderSettings::NOSNIFF]);
        $this->assertSame(1, $values[SecurityHeaderSettings::REFERRER]);
        $this->assertSame(1, $values[SecurityHeaderSettings::PERMISSIONS]);
        $this->assertSame(0, $values[SecurityHeaderSettings::HSTS_ENABLED]);
    }

    public function test_every_new_text_exists_in_english_vietnamese_and_japanese(): void
    {
        $codes = array_keys(SecurityHeadersLanguage::texts());
        $rows = collect((new DataLanguageSeeder())->dataLang())->filter(fn ($row) => in_array($row['code'], $codes, true));

        $this->assertSame(count($codes), $rows->pluck('code')->unique()->count(), 'every text reaches the seeder');
        foreach ($codes as $code) {
            $locations = $rows->where('code', $code)->pluck('location')->sort()->values()->all();
            $this->assertSame(['en', 'ja', 'vi'], $locations, $code . ' has en / vi / ja');
        }
    }
}
