<?php

namespace GP247\Core\Tests\Feature\PersonalData\Requests;

use GP247\Core\AdminShell\Support\SettingsHubTabRegistry;
use GP247\Core\Middleware\PermissionMiddleware;
use GP247\Core\PersonalData\Application\Requests\PrivacySettings;
use GP247\Core\PersonalData\Infrastructure\Requests\PersonalDataInstaller;
use GP247\Core\PersonalData\Livewire\PrivacySettingsForm;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Core\Tests\Feature\PersonalData\Requests\Support\UsesRequestScreen;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;

/**
 * Installing or updating the site gives the screen its permission (assigned to no role), its
 * menu item and default settings, once, keeping what the owner chose; the settings are one
 * policy for the whole site, changed by Administrators only, kept within their limits.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-install-lifecycle, US-personal-data-requests-settings, US-personal-data-requests-permission
 * @aidlc-adr personal-data-requests_access-and-settings
 */
#[Group('personal-data-requests')]
class PrivacySettingsAndInstallTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use UsesRequestScreen;

    /**
     * @param string $table
     * @return \Illuminate\Database\Query\Builder
     */
    private function rows(string $table)
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . $table);
    }

    public function test_the_permission_covers_the_screen_and_the_package_download_under_it_also_on_a_site_installed_before(): void
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        PersonalDataInstaller::ensure($db);
        $this->rows('admin_permission')->where('slug', PersonalDataInstaller::PERMISSION_SLUG)
            ->update(['http_uri' => 'ANY::' . GP247_ADMIN_PREFIX . '/personal_data_requests']);

        PersonalDataInstaller::ensure($db);

        $permission = \GP247\Core\Models\AdminPermission::where('slug', PersonalDataInstaller::PERMISSION_SLUG)->first();
        $this->assertSame(PersonalDataInstaller::permissionUri(GP247_ADMIN_PREFIX), $permission->http_uri);
        $this->assertTrue($permission->passesFor('GET', GP247_ADMIN_PREFIX . '/personal_data_requests', GP247_ADMIN_PREFIX . '/personal_data_requests'));
        $this->assertTrue($permission->passesFor('GET', GP247_ADMIN_PREFIX . '/personal_data_requests/download/{export}/{token}', GP247_ADMIN_PREFIX . '/personal_data_requests/download/01J0000000000000000000000A/abc'));
    }

    public function test_installing_twice_gives_one_permission_assigned_to_no_role_one_menu_item_and_the_default_settings(): void
    {
        $db = DB::connection(GP247_DB_CONNECTION);

        PersonalDataInstaller::ensure($db);
        PersonalDataInstaller::ensure($db);

        $permission = $this->rows('admin_permission')->where('slug', PersonalDataInstaller::PERMISSION_SLUG)->get();
        $this->assertCount(1, $permission);
        $this->assertSame(PersonalDataInstaller::permissionUri(GP247_ADMIN_PREFIX), $permission[0]->http_uri);
        $this->assertSame(0, $this->rows('admin_role_permission')->where('permission_id', $permission[0]->id)->count(), 'only Administrators until the owner assigns it');
        $this->assertSame(1, $this->rows('admin_menu')->where('uri', PersonalDataInstaller::MENU_URI)->count());
        $this->assertSame(3, PrivacySettings::waitDays());
        $this->assertSame(20, PrivacySettings::overdueDays());
    }

    public function test_updating_the_site_keeps_the_days_the_owner_chose(): void
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        PersonalDataInstaller::ensure($db);
        $this->rows('admin_config')->where('key', PrivacySettings::WAIT_DAYS)->where('store_id', (string) GP247_STORE_ID_GLOBAL)->update(['value' => '7']);

        PersonalDataInstaller::ensure($db);

        $this->assertSame(7, PrivacySettings::waitDays());
    }

    public function test_the_settings_are_one_tab_of_the_configuration_hub_for_administrators_only(): void
    {
        $tab = collect(SettingsHubTabRegistry::all())->firstWhere('key', 'personal_data');

        $this->assertNotNull($tab);
        $this->assertSame(GP247_ADMIN_PREFIX . '/personal_data_settings', $tab['authUri']);
        $this->assertContains(GP247_ADMIN_PREFIX . '/personal_data_settings', (new PermissionMiddleware())->administratorOnly());
    }

    public function test_an_administrator_saving_a_wait_longer_than_thirty_days_gets_thirty(): void
    {
        PersonalDataInstaller::ensure(DB::connection(GP247_DB_CONNECTION));
        $this->signInAdministrator();

        Livewire::test(PrivacySettingsForm::class)
            ->set('values.' . PrivacySettings::WAIT_DAYS, '45')
            ->set('values.' . PrivacySettings::OVERDUE_DAYS, '15')
            ->call('save');

        $this->assertSame(30, PrivacySettings::waitDays());
        $this->assertSame(15, PrivacySettings::overdueDays());
    }

    public function test_an_admin_who_is_not_an_administrator_cannot_open_or_save_the_settings_even_with_view_all(): void
    {
        PersonalDataInstaller::ensure(DB::connection(GP247_DB_CONNECTION));

        $this->asUser(viewAll: true);
        $this->assertDenied(fn () => Livewire::test(PrivacySettingsForm::class));

        $this->asUser(slugs: ['admin_personal_data_settings']);
        $this->assertDenied(fn () => Livewire::test(PrivacySettingsForm::class));

        $this->assertSame(3, PrivacySettings::waitDays());
    }
}
