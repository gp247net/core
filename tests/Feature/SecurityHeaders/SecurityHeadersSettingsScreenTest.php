<?php

namespace GP247\Core\Tests\Feature\SecurityHeaders;

use GP247\Core\Middleware\PermissionMiddleware;
use GP247\Core\SecurityHeaders\Application\SecurityHeaderSettings;
use GP247\Core\SecurityHeaders\Livewire\SecurityHeadersSettingsForm;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\Feature\SecurityHeaders\Support\ConfiguresSecurityHeaders;
use GP247\Core\Tests\TestCase;

/**
 * Only an administrator opens the "Security headers" screen and switches headers on or off;
 * what is saved changes the next response at once.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-settings, US-security-headers-hsts-opt-in, US-security-headers-emergency-off
 * @aidlc-adr security-headers_config-hsts-and-kill-switch
 */
#[Group('security-headers')]
class SecurityHeadersSettingsScreenTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use ConfiguresSecurityHeaders;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetConfigMemo();
    }

    public function test_an_administrator_sees_every_header_switched_on_by_default(): void
    {
        $this->asUser(administrator: true);

        Livewire::test(SecurityHeadersSettingsForm::class)
            ->assertSeeHtml('data-testid="security-headers-page"')
            ->assertSet('frame', true)
            ->assertSet('nosniff', true)
            ->assertSet('referrer', true)
            ->assertSet('permissions', true)
            ->assertSet('hstsEnabled', false);
    }

    public function test_an_administrator_switching_a_header_off_removes_it_from_the_next_page(): void
    {
        $this->asUser(administrator: true);
        $this->registerProbeRoutes();

        Livewire::test(SecurityHeadersSettingsForm::class)
            ->set('frame', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $this->assertSame(0, SecurityHeaderSettings::values()[SecurityHeaderSettings::FRAME]);
        $this->get('/__sh-test/page')->assertHeaderMissing('X-Frame-Options')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_turning_hsts_on_is_refused_on_a_site_whose_address_is_not_https(): void
    {
        config(['app.url' => 'http://shop.example']);
        $this->asUser(administrator: true);

        Livewire::test(SecurityHeadersSettingsForm::class)
            ->assertSeeHtml('data-testid="security-headers-hsts-needs-https"')
            ->set('hstsEnabled', true)
            ->call('save')
            ->assertHasErrors('hsts')
            ->assertSet('saved', false);

        $this->assertSame(0, SecurityHeaderSettings::values()[SecurityHeaderSettings::HSTS_ENABLED]);
    }

    public function test_turning_hsts_on_over_https_asks_for_confirmation_and_stores_the_chosen_step(): void
    {
        config(['app.url' => 'https://shop.example']);
        $this->asUser(administrator: true);

        Livewire::test(SecurityHeadersSettingsForm::class)
            ->set('hstsEnabled', true)
            ->assertSeeHtml('wire:confirm')
            ->set('hstsMaxAge', 86400)
            ->call('save')
            ->assertHasNoErrors();

        $values = SecurityHeaderSettings::values();
        $this->assertSame(1, $values[SecurityHeaderSettings::HSTS_ENABLED]);
        $this->assertSame(86400, $values[SecurityHeaderSettings::HSTS_MAX_AGE]);
    }

    public function test_preload_is_refused_on_the_screen_when_its_conditions_are_not_met(): void
    {
        config(['app.url' => 'https://shop.example']);
        $this->asUser(administrator: true);

        Livewire::test(SecurityHeadersSettingsForm::class)
            ->set('hstsEnabled', true)
            ->set('hstsPreload', true)
            ->call('save')
            ->assertHasErrors('hsts');
    }

    public function test_the_screen_says_when_the_emergency_switch_has_silenced_every_header(): void
    {
        config(['gp247-config.security_headers.enabled' => false]);
        $this->asUser(administrator: true);

        Livewire::test(SecurityHeadersSettingsForm::class)
            ->assertSeeHtml('data-testid="security-headers-emergency-off"');
    }

    /**
     * @return array<string, array{bool, array<int, string>}>
     */
    public static function nonAdministrators(): array
    {
        return [
            'view all'                 => [true, []],
            'role holding the screen'  => [false, ['admin_security_headers']],
        ];
    }

    #[DataProvider('nonAdministrators')]
    public function test_a_non_administrator_cannot_open_the_screen(bool $viewAll, array $slugs): void
    {
        $this->asUser(viewAll: $viewAll, slugs: $slugs);

        $this->assertDenied(fn () => Livewire::test(SecurityHeadersSettingsForm::class));
    }

    public function test_the_screen_path_is_administrator_only_in_the_shared_permission_list(): void
    {
        $prefix = defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin';

        $this->assertContains($prefix . '/security_headers', (new PermissionMiddleware())->administratorOnly());
        $this->assertNotNull(app('router')->getRoutes()->getByName('admin.security_headers'));
    }
}
