<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use Closure;
use GP247\Core\AdminShell\Infrastructure\ConfigForm;
use GP247\Core\AdminShell\Infrastructure\ReadinessCheck;
use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * A plugin settings screen says, before anything else, which GP247 settings are still
 * missing for the plugin to work — with the reason and a link to the place to fix it —
 * so an owner never has to hunt for why an enabled plugin does nothing.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-config-form-readiness
 * @aidlc-adr admin-shell_config-form-readiness-seam
 */
class ConfigFormReadinessTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        FixtureReadinessConfigForm::$checks = null;
        FixtureReadinessConfigForm::$seenStore = null;
        $row = AdminConfig::firstOrNew(['group' => 'FixtureReadinessGroup', 'key' => 'fixture_ready_key', 'store_id' => (string) GP247_STORE_ID_GLOBAL]);
        $row->forceFill(['code' => 'fixture', 'sort' => 0, 'detail' => 'fixture', 'security' => 0, 'value' => ''])->save();
        $this->asUser(administrator: true);
    }

    public function test_a_screen_without_readiness_checks_looks_exactly_as_before(): void
    {
        $html = Livewire::test(FixtureReadinessConfigForm::class)->html();

        $this->assertStringNotContainsString('config-form-readiness', $html);
    }

    public function test_missing_settings_are_listed_first_with_their_reason_and_a_link_to_fix_them(): void
    {
        FixtureReadinessConfigForm::$checks = static fn (): array => [
            ReadinessCheck::ok('token', 'Token is set'),
            ReadinessCheck::warning('webhook', 'Webhook URL is local', 'https://example.test/hook', 'Webhook help'),
            ReadinessCheck::error('city', 'Customer addresses do not ask for a province', 'https://example.test/customer', 'Shop configuration → Customer'),
        ];

        $component = Livewire::test(FixtureReadinessConfigForm::class);
        $html = $component->html();

        $this->assertStringContainsString('data-testid="config-form-readiness"', $html);
        $this->assertStringContainsString('data-testid="config-form-readiness-item-city"', $html);
        $this->assertStringContainsString('data-testid="config-form-readiness-fix-city"', $html);
        $this->assertStringContainsString('https://example.test/customer', $html);
        $this->assertStringContainsString('Customer addresses do not ask for a province', $html);
        $this->assertLessThan(strpos($html, 'config-form-readiness-item-webhook'), strpos($html, 'config-form-readiness-item-city'), 'errors come before warnings');
        $this->assertLessThan(strpos($html, 'config-form-table'), strpos($html, 'config-form-readiness"'), 'the check sits above the settings');
        $this->assertSame(['error', 'warning', 'ok'], array_map(static fn (ReadinessCheck $c): string => $c->level, $component->instance()->readinessItems()));
    }

    public function test_when_everything_is_in_place_the_screen_says_so_in_one_line(): void
    {
        FixtureReadinessConfigForm::$checks = static fn (): array => [ReadinessCheck::ok('token', 'Token is set')];

        $html = Livewire::test(FixtureReadinessConfigForm::class)->html();

        $this->assertStringContainsString('data-testid="config-form-readiness-all-ok"', $html);
        $this->assertStringNotContainsString('config-form-readiness-item-token', $html);
    }

    public function test_a_check_that_crashes_is_reported_and_the_screen_still_works(): void
    {
        FixtureReadinessConfigForm::$checks = static function (): array {
            throw new \RuntimeException('boom');
        };

        $html = Livewire::test(FixtureReadinessConfigForm::class)->html();

        $this->assertStringContainsString('data-testid="config-form-readiness-item-readiness"', $html);
        $this->assertStringContainsString('cfg-fixture_ready_key-', $html);
    }

    public function test_saving_reevaluates_the_checks_with_the_value_just_saved(): void
    {
        FixtureReadinessConfigForm::$checks = static function (): array {
            $value = (string) AdminConfig::where('group', 'FixtureReadinessGroup')->where('key', 'fixture_ready_key')
                ->where('store_id', (string) GP247_STORE_ID_GLOBAL)->value('value');

            return [ReadinessCheck::failIf($value === '', 'fixture_ready_key', 'Key is missing', okMessage: 'Key is set')];
        };

        $component = Livewire::test(FixtureReadinessConfigForm::class);
        $this->assertStringContainsString('config-form-readiness-item-fixture_ready_key', $component->html());

        $component->set('values.fixture_ready_key', 'abc')->call('save');

        $this->assertStringContainsString('config-form-readiness-all-ok', $component->html());
    }

    public function test_the_shared_scope_is_checked_against_the_root_store(): void
    {
        FixtureReadinessConfigForm::$checks = static fn (): array => [];

        Livewire::test(FixtureReadinessConfigForm::class)->html();

        $this->assertSame((string) (defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1'), FixtureReadinessConfigForm::$seenStore);
    }

    public function test_a_check_offers_ok_warning_and_error_factories(): void
    {
        $missing = ReadinessCheck::failIf(true, 'k', 'Missing', 'warning', 'https://x.test', 'Fix it', 'Fine');
        $fine = ReadinessCheck::failIf(false, 'k', 'Missing', 'warning', 'https://x.test', 'Fix it', 'Fine');

        $this->assertSame(['warning', 'Missing', 'https://x.test', 'Fix it'], [$missing->level, $missing->message, $missing->fixUrl, $missing->fixLabel]);
        $this->assertTrue($fine->passed());
        $this->assertSame('Fine', $fine->message);
        $this->assertFalse($missing->passed());
    }
}

/**
 * ConfigForm whose readiness checks are set by the test.
 */
class FixtureReadinessConfigForm extends ConfigForm
{
    /** @var Closure|null fn(): array<ReadinessCheck> */
    public static ?Closure $checks = null;

    /** @var string|null Store the last readiness() call was asked about. */
    public static ?string $seenStore = null;

    protected ?string $permission = null;

    protected function group(): string
    {
        return 'FixtureReadinessGroup';
    }

    protected function heading(): string
    {
        return 'Fixture readiness';
    }

    protected function keys(): array
    {
        return ['fixture_ready_key'];
    }

    protected function readiness(): array
    {
        self::$seenStore = (string) $this->readinessStoreId();

        return self::$checks !== null ? (self::$checks)() : [];
    }
}
