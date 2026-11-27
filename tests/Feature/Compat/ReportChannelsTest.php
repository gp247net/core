<?php

namespace GP247\Core\Tests\Feature\Compat;

use GP247\Core\AdminShell\Http\Livewire\GlobalConfigForm;
use GP247\Core\Api\Sync\OutboundUrlGuard;
use GP247\Core\Models\AdminConfig;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/**
 * Platform error reports reach the team through the fields of the admin "Webhook" screen:
 * Slack, Google Chat and a third-party webhook — each one a POST of {"text": ...}. An empty
 * field falls back to .env (LOG_SLACK_WEBHOOK_URL, GP247_REPORT_WEBHOOK_URL); the screen
 * wins over .env. Each send is time-bounded, a repeated report is sent once per window,
 * unsafe URLs are refused on save and never called, and a failing channel never breaks
 * the request. The URLs are secrets: stored encrypted, never shown back.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-report-channels-env
 * @aidlc-adr compat-foundation_report-channels-env
 */
class ReportChannelsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private const SLACK_URL = 'https://hooks.slack.example.com/services/T000/B000/slack-secret';
    private const GOOGLE_URL = 'https://chat.example.com/v1/spaces/AAA/messages?key=k1&token=google-secret';
    private const THIRD_URL = 'https://mattermost.example.com/hooks/third-secret';
    private const ENV_SLACK_URL = 'https://hooks.slack.example.com/services/T999/B999/env-secret';
    private const ENV_THIRD_URL = 'https://discord.example.com/api/webhooks/1/env-third/slack';

    private const KEYS = ['LOG_SLACK_WEBHOOK_URL', 'GOOGLE_CHAT_WEBHOOK_URL', 'THIRD_PARTY_WEBHOOK_URL'];

    /** @var \ArrayObject<int, array{0: string, 1: string}> Level and message of each local log line. */
    private \ArrayObject $logged;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'logging.channels.slack.url' => '',
            'gp247-config.report.webhook_url' => '',
            'gp247-config.report.timeout' => 3,
            'gp247-config.report.throttle_seconds' => 60,
            'gp247-config.report.max_per_minute' => 30,
        ]);
        // WHY: no DNS in tests — every example host resolves to a public documentation address.
        OutboundUrlGuard::resolveUsing(fn (string $host) => str_starts_with($host, 'internal.') ? ['10.0.0.5'] : ['93.184.216.34']);
        Cache::flush();
        foreach (self::KEYS as $key) {
            $this->setField($key, '');
        }
    }

    protected function tearDown(): void
    {
        OutboundUrlGuard::resolveUsing(null);
        AdminConfig::forgetMemo();
        parent::tearDown();
    }

    /**
     * Record every line written to a local log channel instead of the log file.
     *
     * @return void
     */
    private function captureLocalLog(): void
    {
        $this->logged = new \ArrayObject();
        $lines = $this->logged;
        $channel = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            $channel->shouldReceive($level)->andReturnUsing(function ($msg) use ($lines, $level) {
                $lines->append([$level, (string) $msg]);
            });
        }
        Log::shouldReceive('channel')->andReturn($channel);
    }

    /**
     * Messages of the captured local log lines.
     *
     * @return string
     */
    private function localLog(): string
    {
        return implode("\n", array_map(fn ($line) => $line[1], $this->logged->getArrayCopy()));
    }

    /**
     * Store a value in a field of the Webhook screen (as typed, like a value saved before this release).
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    private function setField(string $key, string $value): void
    {
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $table->where('key', $key)->where('store_id', GP247_STORE_ID_GLOBAL)->delete();
        $table->insert(['group' => 'global', 'code' => 'webhook_config', 'key' => $key, 'value' => $value,
            'sort' => 0, 'detail' => 'admin.config.' . $key, 'store_id' => GP247_STORE_ID_GLOBAL]);
        AdminConfig::forgetMemo();
    }

    /**
     * Raw stored value of a field.
     *
     * @param string $key
     * @return string|null
     */
    private function storedValue(string $key): ?string
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('key', $key)->where('store_id', GP247_STORE_ID_GLOBAL)->value('value');
    }

    // ---- sending -------------------------------------------------------------------------

    public function test_a_report_reaches_every_field_of_the_screen_as_text_with_the_site_line(): void
    {
        $this->captureLocalLog();
        $this->setField('LOG_SLACK_WEBHOOK_URL', self::SLACK_URL);
        $this->setField('GOOGLE_CHAT_WEBHOOK_URL', self::GOOGLE_URL);
        $this->setField('THIRD_PARTY_WEBHOOK_URL', self::THIRD_URL);
        config(['app.url' => 'https://shop.example.test']);
        Http::fake(['*' => Http::response('ok', 200)]);

        gp247_report('probe-all-fields');

        Http::assertSentCount(3);
        foreach ([self::SLACK_URL, self::GOOGLE_URL, self::THIRD_URL] as $url) {
            Http::assertSent(function (HttpRequest $request) use ($url) {
                $body = json_decode($request->body(), true);
                return $request->url() === $url && $request->method() === 'POST'
                    && is_array($body) && array_keys($body) === ['text']
                    && str_contains($body['text'], 'probe-all-fields')
                    && str_contains($body['text'], '[site] https://shop.example.test');
            });
        }
        $this->assertStringContainsString('probe-all-fields', $this->localLog());
    }

    public function test_an_empty_field_falls_back_to_env_and_the_screen_wins_over_env(): void
    {
        $this->captureLocalLog();
        config(['logging.channels.slack.url' => self::ENV_SLACK_URL, 'gp247-config.report.webhook_url' => self::ENV_THIRD_URL]);
        Http::fake(['*' => Http::response('ok', 200)]);

        gp247_report('probe-env-fallback');
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::ENV_SLACK_URL);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::ENV_THIRD_URL);

        $this->setField('LOG_SLACK_WEBHOOK_URL', self::SLACK_URL);
        $this->setField('THIRD_PARTY_WEBHOOK_URL', self::THIRD_URL);
        Http::fake(['*' => Http::response('ok', 200)]);
        gp247_report('probe-screen-wins');

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::SLACK_URL);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::THIRD_URL);
        Http::assertNotSent(fn (HttpRequest $request) => in_array($request->url(), [self::ENV_SLACK_URL, self::ENV_THIRD_URL], true));
    }

    public function test_nothing_leaves_the_site_when_no_channel_is_configured(): void
    {
        $this->captureLocalLog();
        Http::fake();

        gp247_report('probe-no-channel');

        Http::assertNothingSent();
        $this->assertStringContainsString('probe-no-channel', $this->localLog());
    }

    public function test_a_webhook_that_is_not_public_https_is_never_called(): void
    {
        $this->captureLocalLog();
        Http::fake();
        foreach (['http://chat.example.com/hook', 'https://internal.example.com/hook', 'https://127.0.0.1/hook'] as $i => $url) {
            config(['gp247-config.report.webhook_url' => $url]);
            gp247_report('probe-unsafe-' . $i);
        }

        Http::assertNothingSent();
        $this->assertStringContainsString('probe-unsafe-2', $this->localLog());
    }

    public function test_the_same_report_repeated_inside_the_window_is_sent_once_and_still_logged_each_time(): void
    {
        $this->captureLocalLog();
        $this->setField('THIRD_PARTY_WEBHOOK_URL', self::THIRD_URL);
        Http::fake(['*' => Http::response('ok', 200)]);

        gp247_report('probe-repeat');
        gp247_report('probe-repeat');
        gp247_report('probe-repeat');

        Http::assertSentCount(1);
        $this->assertSame(3, substr_count($this->localLog(), 'probe-repeat'));
    }

    public function test_an_exception_repeated_from_different_visitors_is_sent_once(): void
    {
        $this->captureLocalLog();
        $this->setField('THIRD_PARTY_WEBHOOK_URL', self::THIRD_URL);
        Http::fake(['*' => Http::response('ok', 200)]);
        $error = new \RuntimeException('probe-same-failure');

        // WHY: the exception report carries the visitor IP and URL, so its text differs on every request.
        $this->app->instance('request', \Illuminate\Http\Request::create('https://shop.example.test/a', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.1']));
        gp247_handle_exception($error);
        $this->app->instance('request', \Illuminate\Http\Request::create('https://shop.example.test/b', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.2']));
        gp247_handle_exception($error);

        Http::assertSentCount(1);
    }

    public function test_a_failing_or_unreachable_webhook_never_breaks_the_report_or_leaks_its_url(): void
    {
        $this->captureLocalLog();
        $this->setField('GOOGLE_CHAT_WEBHOOK_URL', self::GOOGLE_URL);
        Http::fake(['*' => Http::response('down', 500)]);
        gp247_report('probe-failing-500');

        Http::fake(fn () => throw new ConnectionException('timed out'));
        gp247_report('probe-failing-timeout');

        $log = $this->localLog();
        $this->assertStringContainsString('probe-failing-500', $log);
        $this->assertStringContainsString('probe-failing-timeout', $log);
        $this->assertStringContainsString('chat.example.com', $log);
        $this->assertStringNotContainsString('google-secret', $log);
    }

    public function test_a_send_is_bounded_by_the_configured_timeout(): void
    {
        $this->captureLocalLog();
        $this->setField('THIRD_PARTY_WEBHOOK_URL', self::THIRD_URL);
        config(['gp247-config.report.timeout' => 2]);
        $options = [];
        Http::fake(function (HttpRequest $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;
            return Http::response('ok', 200);
        });

        gp247_report('probe-timeout-bound');

        $this->assertSame(2, (int) ($options['timeout'] ?? 0));
        $this->assertGreaterThan(0, (float) ($options['connect_timeout'] ?? 0));
        $this->assertLessThanOrEqual(2, (float) ($options['connect_timeout'] ?? 0));
        $this->assertFalse($options['allow_redirects'] ?? true);
    }

    public function test_an_exception_is_reported_as_a_readable_message(): void
    {
        $this->captureLocalLog();

        gp247_report(new \RuntimeException('probe-readable-exception'));

        $log = $this->localLog();
        $this->assertStringContainsString('RuntimeException: probe-readable-exception', $log);
        $this->assertStringContainsString(basename(__FILE__) . ':', $log);
        $this->assertStringNotContainsString('{}', $log);
    }

    // ---- the Webhook screen --------------------------------------------------------------

    public function test_the_screen_shows_slack_google_chat_and_third_party_but_no_chatwork(): void
    {
        $this->asUser(slugs: ['admin_config']);

        $component = Livewire::test(GlobalConfigForm::class);

        $this->assertSame(self::KEYS, array_keys($component->get('values')));
        // The screen explains itself: one block note, a note and an example URL for every field.
        $component->assertSeeHtml('data-testid="config-form-section-report-webhooks"');
        foreach (self::KEYS as $key) {
            $component->assertSeeHtml('data-testid="config-form-note-' . $key . '"');
        }
        $component->assertSeeHtml('placeholder="https://hooks.slack.com/services/T.../B.../..."');
        $component->assertSeeHtml('placeholder="https://chat.example.com/hooks/xxxxxxxxxxxxxxxx"');
    }

    public function test_saving_a_url_that_is_not_public_https_is_refused_and_nothing_is_stored(): void
    {
        $this->asUser(slugs: ['admin_config']);

        Livewire::test(GlobalConfigForm::class)
            ->set('values.GOOGLE_CHAT_WEBHOOK_URL', self::GOOGLE_URL)
            ->set('values.THIRD_PARTY_WEBHOOK_URL', 'https://internal.example.com/hook')
            ->call('save')
            ->assertHasErrors(['values.THIRD_PARTY_WEBHOOK_URL'])
            ->assertHasNoErrors(['values.GOOGLE_CHAT_WEBHOOK_URL']);

        $this->assertSame('', (string) $this->storedValue('GOOGLE_CHAT_WEBHOOK_URL'));
        $this->assertSame('', (string) $this->storedValue('THIRD_PARTY_WEBHOOK_URL'));

        Livewire::test(GlobalConfigForm::class)
            ->set('values.LOG_SLACK_WEBHOOK_URL', 'http://hooks.slack.example.com/plain')
            ->call('save')
            ->assertHasErrors(['values.LOG_SLACK_WEBHOOK_URL']);
    }

    public function test_a_saved_url_is_stored_encrypted_never_shown_back_and_kept_when_left_empty(): void
    {
        $this->asUser(slugs: ['admin_config']);

        Livewire::test(GlobalConfigForm::class)
            ->set('values.THIRD_PARTY_WEBHOOK_URL', self::THIRD_URL)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('values.THIRD_PARTY_WEBHOOK_URL', '');

        $stored = (string) $this->storedValue('THIRD_PARTY_WEBHOOK_URL');
        $this->assertStringNotContainsString('third-secret', $stored);
        $this->assertSame(self::THIRD_URL, gp247_secret_decrypt($stored));

        AdminConfig::forgetMemo();
        $reloaded = Livewire::test(GlobalConfigForm::class);
        $reloaded->assertSet('values.THIRD_PARTY_WEBHOOK_URL', '');
        $this->assertTrue($reloaded->instance()->secretStateOf('THIRD_PARTY_WEBHOOK_URL'));

        $reloaded->call('save')->assertHasNoErrors();
        $this->assertSame(self::THIRD_URL, gp247_secret_decrypt((string) $this->storedValue('THIRD_PARTY_WEBHOOK_URL')));
    }

    public function test_a_saved_url_can_be_cleared_from_the_screen_and_is_then_no_longer_called(): void
    {
        $this->asUser(slugs: ['admin_config']);
        Livewire::test(GlobalConfigForm::class)
            ->set('values.GOOGLE_CHAT_WEBHOOK_URL', self::GOOGLE_URL)
            ->call('save')
            ->assertHasNoErrors();
        AdminConfig::forgetMemo();

        $component = Livewire::test(GlobalConfigForm::class);
        $component->assertSeeHtml('data-testid="config-form-secret-clear-GOOGLE_CHAT_WEBHOOK_URL"');
        $component->call('clearSecret', 'GOOGLE_CHAT_WEBHOOK_URL');

        $this->assertSame('', (string) $this->storedValue('GOOGLE_CHAT_WEBHOOK_URL'));
        $this->assertFalse($component->instance()->secretStateOf('GOOGLE_CHAT_WEBHOOK_URL'));
        $component->assertDontSeeHtml('data-testid="config-form-secret-clear-GOOGLE_CHAT_WEBHOOK_URL"');

        AdminConfig::forgetMemo();
        $this->captureLocalLog();
        Http::fake();
        gp247_report('probe-after-clear');
        Http::assertNothingSent();
    }

    public function test_only_a_saved_secret_of_this_screen_can_be_cleared_and_only_with_write_permission(): void
    {
        $this->setField('THIRD_PARTY_WEBHOOK_URL', self::THIRD_URL);

        $this->asUser(viewAll: true);
        $readOnly = Livewire::test(GlobalConfigForm::class);
        $this->assertDenied(fn () => $readOnly->call('clearSecret', 'THIRD_PARTY_WEBHOOK_URL'));
        $this->assertSame(self::THIRD_URL, (string) $this->storedValue('THIRD_PARTY_WEBHOOK_URL'));

        // A key outside the screen (here a cache setting of the same group) is never touched.
        $this->asUser(slugs: ['admin_config']);
        $before = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('key', 'cache_status')->where('store_id', GP247_STORE_ID_GLOBAL)->value('value');
        Livewire::test(GlobalConfigForm::class)->call('clearSecret', 'cache_status');
        $this->assertSame($before, DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('key', 'cache_status')->where('store_id', GP247_STORE_ID_GLOBAL)->value('value'));
    }

    // ---- upgrade ---------------------------------------------------------------------------

    public function test_the_upgrade_drops_chatwork_and_adds_the_third_party_field(): void
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $this->setField('CHATWORK_CHAT_WEBHOOK_URL', 'https://chatwork.example.com/old');
        $db->table(GP247_DB_PREFIX . 'admin_config')->where('key', 'THIRD_PARTY_WEBHOOK_URL')->delete();

        $migration = $this->upgradeMigration();
        $migration->up();
        $migration->up();

        $config = $db->table(GP247_DB_PREFIX . 'admin_config');
        $this->assertFalse((clone $config)->where('key', 'CHATWORK_CHAT_WEBHOOK_URL')->exists());
        $this->assertSame(1, (clone $config)->where('key', 'THIRD_PARTY_WEBHOOK_URL')->where('group', 'global')
            ->where('code', 'webhook_config')->where('store_id', GP247_STORE_ID_GLOBAL)->count());
        $this->assertSame('', (string) $this->storedValue('THIRD_PARTY_WEBHOOK_URL'));
    }

    /**
     * The upgrade migration of the Webhook screen fields.
     *
     * @return \Illuminate\Database\Migrations\Migration
     */
    private function upgradeMigration(): object
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Database/Migrations/upgrade/*_webhook_report_channels.php');
        $this->assertCount(1, $files);

        return require $files[0];
    }
}
