<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Http\Livewire\AdminAlerts;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\AdminShell\Support\AdminAlertRegistry;
use GP247\Core\AdminShell\Support\AdminAlertService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * The "System alerts" dashboard block: only an Administrator sees it, it is a one-line
 * summary (worst level, counts, at most two critical names, a link to the page),
 * results are cached, a broken check never breaks the block and nothing calls out.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 * @aidlc-adr admin-shell_system-alert-registry
 * @aidlc-adr admin-shell_system-alerts-page
 */
class AdminAlertsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /** @var array<string, class-string> */
    private array $registered = [];

    /**
     * Start every test from an empty alert cache and remember the core registry
     * so a test that registers its own check can restore it.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registered = AdminAlertRegistry::all();
        Cache::flush();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        AdminAlertRegistry::reset();
        foreach ($this->registered as $class) {
            AdminAlertRegistry::register($class);
        }

        parent::tearDown();
    }

    public function test_an_administrator_sees_a_one_line_summary_at_the_worst_level(): void
    {
        $this->asUser(administrator: true);
        config(['app.debug' => true]);

        Livewire::withoutLazyLoading()->test(AdminAlerts::class)
            ->assertSeeHtml('data-testid="admin-alerts"')
            ->assertSeeHtml('data-testid="admin-alerts-summary"')
            ->assertSeeHtml('data-level="critical"')
            ->assertSeeHtml('data-testid="admin-alerts-view-all"');
    }

    public function test_the_block_no_longer_lists_items_fix_buttons_or_check_again(): void
    {
        $this->asUser(administrator: true);
        config(['app.debug' => true]);

        Livewire::withoutLazyLoading()->test(AdminAlerts::class)
            ->assertDontSeeHtml('data-testid="admin-alerts-item-')
            ->assertDontSeeHtml('data-testid="admin-alerts-fix-')
            ->assertDontSeeHtml('data-testid="admin-alerts-doc-')
            ->assertDontSeeHtml('data-testid="admin-alerts-recheck"')
            ->assertDontSeeHtml('data-testid="admin-alerts-passed-toggle"');
    }

    public function test_the_summary_names_at_most_two_critical_checks(): void
    {
        $this->asUser(administrator: true);
        AdminAlertRegistry::reset();
        AdminAlertRegistry::register(CriticalAlertCheckA::class);
        AdminAlertRegistry::register(CriticalAlertCheckB::class);
        AdminAlertRegistry::register(CriticalAlertCheckC::class);

        Livewire::withoutLazyLoading()->test(AdminAlerts::class)
            ->assertSeeHtml('data-level="critical"')
            ->assertSee('Critical issue A')
            ->assertSee('Critical issue B')
            ->assertDontSee('Critical issue C');
    }

    public function test_the_summary_turns_to_review_when_only_warnings_remain(): void
    {
        $this->asUser(administrator: true);
        AdminAlertRegistry::reset();
        AdminAlertRegistry::register(WarningAlertCheck::class);
        AdminAlertRegistry::register(CountingAlertCheck::class);

        Livewire::withoutLazyLoading()->test(AdminAlerts::class)
            ->assertSeeHtml('data-level="warning"')
            ->assertDontSee('Warning issue');
    }

    public function test_the_summary_says_every_check_passed_and_links_to_details(): void
    {
        $this->asUser(administrator: true);
        AdminAlertRegistry::reset();
        AdminAlertRegistry::register(CountingAlertCheck::class);

        Livewire::withoutLazyLoading()->test(AdminAlerts::class)
            ->assertSeeHtml('data-level="ok"')
            ->assertSeeHtml('data-testid="admin-alerts-view-all"');
    }

    /**
     * @return array<string, array{0: bool, 1: array<int, string>}>
     */
    public static function nonAdministrators(): array
    {
        return [
            'view-all' => [true, []],
            'store-admin' => [false, ['admin_order', 'admin_product']],
            'layout editor' => [false, ['admin_home_layout']],
        ];
    }

    /**
     * @param bool               $viewAll
     * @param array<int, string> $slugs
     * @return void
     */
    #[DataProvider('nonAdministrators')]
    public function test_a_non_administrator_gets_no_alert_markup_data_or_count(bool $viewAll, array $slugs): void
    {
        config(['app.debug' => true]);
        $this->asUser(viewAll: $viewAll, slugs: $slugs);

        $html = view('gp247-admin::component.alerts')->render();
        $this->assertStringNotContainsString('admin-alerts', $html);

        $service = app(AdminAlertService::class);
        $this->assertFalse($service->allowed());
        $this->assertSame([], $service->results()['items']);
        $this->assertSame(0, $service->criticalCount());

        $this->assertDenied(fn () => Livewire::withoutLazyLoading()->test(AdminAlerts::class));
    }

    public function test_the_header_count_only_reads_cached_results(): void
    {
        $this->asUser(administrator: true);
        config(['app.debug' => true]);
        $service = app(AdminAlertService::class);

        $this->assertSame(0, $service->criticalCount(), 'nothing evaluated yet: the header must not run checks');

        $service->results();

        $this->assertGreaterThanOrEqual(1, $service->criticalCount());
    }

    public function test_a_check_that_throws_is_skipped_and_the_block_still_renders(): void
    {
        $this->asUser(administrator: true);
        AdminAlertRegistry::register(ThrowingAlertCheck::class);

        $component = Livewire::withoutLazyLoading()->test(AdminAlerts::class)->assertStatus(200);

        $component->assertDontSeeHtml('admin-alerts-item-test_throwing')
            ->assertDontSeeHtml('admin-alerts-passed-test_throwing');
        $keys = array_map(fn (AdminAlert $a) => $a->key, app(AdminAlertService::class)->results()['items']);
        $this->assertNotContains('test_throwing', $keys);
        $this->assertContains('app_debug', $keys);
    }

    public function test_a_cached_check_is_not_evaluated_again_within_its_ttl(): void
    {
        $this->asUser(administrator: true);
        CountingAlertCheck::$calls = 0;
        AdminAlertRegistry::register(CountingAlertCheck::class);
        $service = app(AdminAlertService::class);

        $service->results();
        $service->results();
        $this->assertSame(1, CountingAlertCheck::$calls);

        $service->refresh();
        $service->results();
        $this->assertSame(2, CountingAlertCheck::$calls);
    }

    public function test_evaluating_every_check_sends_no_outbound_http_request(): void
    {
        Http::fake();
        $this->asUser(administrator: true);

        app(AdminAlertService::class)->results();

        Http::assertNothingSent();
    }

    public function test_items_are_ordered_critical_then_warning_then_info_then_passed(): void
    {
        $this->asUser(administrator: true);

        $weights = array_map(
            fn (AdminAlert $a) => $a->weight(),
            app(AdminAlertService::class)->results()['items']
        );
        $sorted = $weights;
        sort($sorted);

        $this->assertSame($sorted, $weights);
    }
}

/**
 * Test double: a check whose evaluation always fails.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 */
class ThrowingAlertCheck implements AdminAlertCheck
{
    /** @return string */
    public function key(): string
    {
        return 'test_throwing';
    }

    /** @return int */
    public function ttl(): int
    {
        return 600;
    }

    /**
     * @return AdminAlert
     * @throws \RuntimeException Always.
     */
    public function evaluate(): AdminAlert
    {
        throw new \RuntimeException('boom');
    }
}

/**
 * Test double: counts how many times it is evaluated.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 */
class CountingAlertCheck implements AdminAlertCheck
{
    public static int $calls = 0;

    /** @return string */
    public function key(): string
    {
        return 'test_counting';
    }

    /** @return int */
    public function ttl(): int
    {
        return 600;
    }

    /** @return AdminAlert */
    public function evaluate(): AdminAlert
    {
        self::$calls++;

        return AdminAlert::ok('test_counting', 'ok');
    }
}

/**
 * Test double: a fixed critical result (first of three, to test the two-name limit).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 */
class CriticalAlertCheckA implements AdminAlertCheck
{
    /** @return string */
    public function key(): string
    {
        return 'test_critical_a';
    }

    /** @return int */
    public function ttl(): int
    {
        return 600;
    }

    /** @return AdminAlert */
    public function evaluate(): AdminAlert
    {
        return AdminAlert::critical($this->key(), 'Critical issue A');
    }
}

/**
 * Test double: a fixed critical result.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 */
class CriticalAlertCheckB extends CriticalAlertCheckA
{
    /** @return string */
    public function key(): string
    {
        return 'test_critical_b';
    }

    /** @return AdminAlert */
    public function evaluate(): AdminAlert
    {
        return AdminAlert::critical($this->key(), 'Critical issue B');
    }
}

/**
 * Test double: a fixed critical result.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 */
class CriticalAlertCheckC extends CriticalAlertCheckA
{
    /** @return string */
    public function key(): string
    {
        return 'test_critical_c';
    }

    /** @return AdminAlert */
    public function evaluate(): AdminAlert
    {
        return AdminAlert::critical($this->key(), 'Critical issue C');
    }
}

/**
 * Test double: a fixed warning result.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 */
class WarningAlertCheck extends CriticalAlertCheckA
{
    /** @return string */
    public function key(): string
    {
        return 'test_warning';
    }

    /** @return AdminAlert */
    public function evaluate(): AdminAlert
    {
        return AdminAlert::warning($this->key(), 'Warning issue');
    }
}
