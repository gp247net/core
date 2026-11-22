<?php

namespace GP247\Core\Tests\Feature\SystemCli;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use GP247\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies gp247:cache-rebuild (US-CLI-003): the command
 * — via gp247_extension_after_update() — clears the compiled Blade views so
 * freshly published views take effect, and reports the standard success envelope.
 *
 * Scope note: like UpdateOrchestratorTest, this exercises only the non-destructive
 * path. In the test environment no route/config cache file exists, so route:cache /
 * config:cache never run; view:clear (which only deletes regenerated-on-demand
 * compiled files) is the observable effect asserted here.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-003
 * @aidlc-adr system-cli_cache-rebuild-scope
 */
#[Group('serial')]
class CacheRebuildCommandTest extends TestCase
{
    /**
     * Run a gp247 command in JSON mode and decode its single envelope.
     *
     * @param string               $command
     * @param array<string, mixed> $args
     * @return array{code: int, data: array}
     */
    protected function runJson(string $command, array $args = []): array
    {
        $buffer = new BufferedOutput();
        $code = Artisan::call($command, array_merge($args, ['--json' => true]), $buffer);

        // WHY: under a BufferedOutput there is no separate STDERR stream, so the
        // base command's human lines (info()/warn(), routed to writeStderr) fall
        // back into the same buffer as the single JSON envelope. On a real TTY
        // STDOUT carries only the envelope; here we isolate it by taking the last
        // line that parses as the envelope object.
        $lines = array_filter(array_map('trim', explode("\n", $buffer->fetch())), 'strlen');
        $envelope = [];
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                $envelope = $decoded;
                break;
            }
        }

        return ['code' => $code, 'data' => $envelope];
    }

    /**
     * A stale compiled Blade file must be removed by gp247:cache-rebuild (proves
     * view:clear ran), and the command must return the success envelope with
     * data.rebuilt = true and exit code 0.
     *
     * This is the regression guard for the spec-vs-code drift closed by the mod:
     * before it, the helper never touched compiled views, so this file survived.
     *
     * @return void
     */
    public function test_cache_rebuild_clears_stale_compiled_views(): void
    {
        $compiledPath = config('view.compiled');
        File::ensureDirectoryExists($compiledPath);

        // A hand-planted "stale" compiled view whose mtime Blade would consider
        // fresh — only an explicit view:clear removes it.
        $stale = $compiledPath . '/gp247_cache_rebuild_stale_test.php';
        File::put($stale, '<?php /* stale compiled blade for cache-rebuild test */');
        $this->assertFileExists($stale);

        $res = $this->runJson('gp247:cache-rebuild');

        $this->assertSame(0, $res['code']);
        $this->assertTrue($res['data']['ok'] ?? false, 'envelope must report ok');
        $this->assertTrue($res['data']['data']['rebuilt'] ?? false, 'data.rebuilt must be true');
        $this->assertFileDoesNotExist($stale);
    }

    /**
     * With no route/config cache file present (the shared-host default), the
     * command must not fail: the route/config branches are skipped, view:clear
     * still runs, and the success envelope is returned. Proves the soft, no-op
     * path stays non-fatal.
     *
     * @return void
     */
    public function test_cache_rebuild_succeeds_when_no_route_config_cache(): void
    {
        // Ensure the app is running uncached (route/config cache files absent).
        File::delete(app()->getCachedRoutesPath());
        File::delete(app()->getCachedConfigPath());

        $res = $this->runJson('gp247:cache-rebuild');

        $this->assertSame(0, $res['code']);
        $this->assertTrue($res['data']['ok'] ?? false);
        $this->assertTrue($res['data']['data']['rebuilt'] ?? false);
    }

    /**
     * The command description must advertise the view cache too, so `list`/`help`
     * reflect the actual scope (route/config/view) after the mod.
     *
     * @return void
     */
    public function test_description_mentions_view_cache(): void
    {
        $description = Artisan::all()['gp247:cache-rebuild']->getDescription();

        $this->assertStringContainsStringIgnoringCase('view', $description);
    }
}
