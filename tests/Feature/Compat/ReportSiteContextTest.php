<?php

namespace GP247\Core\Tests\Feature\Compat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use GP247\Core\Tests\TestCase;

/**
 * Every platform report (gp247_report → Slack + daily log) says which site sent it:
 * APP_URL, APP_ENV and the request (host/method/path) or the CLI command — never the
 * query string or command arguments, which may carry tokens or passwords.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-report-site-context
 * @aidlc-adr compat-foundation_report-site-context
 */
class ReportSiteContextTest extends TestCase
{
    /** @var array<int, string>|null */
    private ?array $argvBackup = null;

    protected function tearDown(): void
    {
        if ($this->argvBackup !== null) {
            $_SERVER['argv'] = $this->argvBackup;
            $this->argvBackup = null;
        }
        parent::tearDown();
    }

    /**
     * Capture every message gp247_report sends to any log channel.
     *
     * @return \ArrayObject<int, string>
     */
    private function captureReports(): \ArrayObject
    {
        $messages = new \ArrayObject();
        $channel = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $channel->shouldReceive('emergency')->andReturnUsing(function ($msg) use ($messages) {
            $messages->append((string) $msg);
        });
        Log::shouldReceive('channel')->andReturn($channel);

        return $messages;
    }

    /**
     * Make the app behave as if serving an HTTP request.
     *
     * @param Request $request
     * @return void
     */
    private function simulateWebRequest(Request $request): void
    {
        $prop = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $prop->setAccessible(true);
        $prop->setValue($this->app, false);
        $this->app->instance('request', $request);
    }

    public function test_a_report_raised_during_a_web_request_names_the_site_environment_and_request(): void
    {
        config(['app.url' => 'https://shop-a.example.test', 'app.env' => 'production']);
        $this->simulateWebRequest(Request::create('https://shop-a.example.test/checkout/confirm', 'POST'));
        $reports = $this->captureReports();

        gp247_report('probe-web');

        $this->assertNotEmpty($reports);
        $msg = $reports[0];
        $this->assertStringContainsString('https://shop-a.example.test', $msg);
        $this->assertStringContainsString('env=production', $msg);
        $this->assertStringContainsString('host=shop-a.example.test', $msg);
        $this->assertStringContainsString('POST /checkout/confirm', $msg);
        $this->assertStringContainsString('probe-web', $msg);
    }

    public function test_a_report_never_carries_the_request_query_string(): void
    {
        $this->simulateWebRequest(Request::create('https://shop-a.example.test/password/reset?token=SECRET-TOKEN-123&email=a@b.c', 'GET'));
        $reports = $this->captureReports();

        gp247_report('probe-query');

        $this->assertNotEmpty($reports);
        $this->assertStringContainsString('GET /password/reset', $reports[0]);
        $this->assertStringNotContainsString('SECRET-TOKEN-123', $reports[0]);
        $this->assertStringNotContainsString('a@b.c', $reports[0]);
    }

    public function test_a_report_raised_from_the_command_line_names_the_command_but_not_its_arguments(): void
    {
        config(['app.url' => 'https://shop-b.example.test']);
        $this->argvBackup = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['artisan', 'gp247:probe-command', '--password=hunter2-secret', 'positional-secret'];
        $reports = $this->captureReports();

        gp247_report('probe-cli');

        $this->assertNotEmpty($reports);
        $msg = $reports[0];
        $this->assertStringContainsString('https://shop-b.example.test', $msg);
        $this->assertStringContainsString('cli=gp247:probe-command', $msg);
        $this->assertStringNotContainsString('hunter2-secret', $msg);
        $this->assertStringNotContainsString('positional-secret', $msg);
    }
}
