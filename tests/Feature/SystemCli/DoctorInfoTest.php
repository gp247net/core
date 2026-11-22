<?php

namespace GP247\Core\Tests\Feature\SystemCli;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use GP247\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the read-only diagnostic/status commands and that the orchestrator
 * commands are registered (US-CLI-003/US-CLI-004).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-004
 * @aidlc-adr system-cli_output-contract
 */
#[Group('serial')]
class DoctorInfoTest extends TestCase
{
    /**
     * @param string $command
     * @param array<string, mixed> $args
     * @return array{code: int, data: array}
     */
    protected function runJson(string $command, array $args = []): array
    {
        $buffer = new BufferedOutput();
        $code = Artisan::call($command, array_merge($args, ['--json' => true]), $buffer);
        return ['code' => $code, 'data' => json_decode(trim($buffer->fetch()), true) ?: []];
    }

    /**
     * doctor returns a list of environment checks.
     *
     * @return void
     */
    public function test_doctor_reports_checks(): void
    {
        $res = $this->runJson('gp247:doctor');

        $this->assertArrayHasKey('checks', $res['data']['data']);
        $this->assertNotEmpty($res['data']['data']['checks']);
        // On a working test environment (DB reachable, php ok) doctor should pass.
        $this->assertTrue($res['data']['ok']);
        $this->assertSame(0, $res['code']);
    }

    /**
     * The php_version check states the floor it enforces, and that floor is the
     * `php` constraint of gp247/core's composer.json (it used to pass PHP 8.2,
     * which the platform cannot boot on).
     *
     * @return void
     */
    public function test_doctor_php_check_enforces_the_core_composer_php_floor(): void
    {
        $composer = json_decode(file_get_contents(base_path('vendor/gp247/core/composer.json')), true);
        preg_match('/(\d+\.\d+)/', $composer['require']['php'] ?? '', $m);
        $this->assertNotEmpty($m, 'vendor/gp247/core/composer.json must declare a php constraint');

        $row = $this->checkNamed($this->runJson('gp247:doctor'), 'php_version');

        $this->assertNotNull($row, 'doctor must report a php_version check (US-CLI-004)');
        $this->assertStringContainsString(
            '>= ' . $m[1],
            $row['detail'],
            'doctor php_version must state the composer.json floor it enforces (US-CLI-004)'
        );
        $this->assertSame('pass', $row['status'], 'the test runner PHP satisfies the floor');
    }

    /**
     * Find one check row by name.
     *
     * @param array{code: int, data: array} $res
     * @param string $name
     * @return array{name: string, status: string, detail: string}|null
     */
    protected function checkNamed(array $res, string $name): ?array
    {
        foreach ($res['data']['data']['checks'] ?? [] as $check) {
            if (($check['name'] ?? '') === $name) {
                return $check;
            }
        }

        return null;
    }

    /**
     * Make the application report its routes as cached for the body only (the
     * container memoises routesAreCached() in 'routes.cached'); the site's real
     * bootstrap/cache is never touched.
     *
     * @param callable $body
     * @return void
     */
    protected function withFakeRouteCache(callable $body): void
    {
        app()->instance('routes.cached', true);
        try {
            $body();
        } finally {
            app()->forgetInstance('routes.cached');
        }
    }

    /**
     * api_route_gate passes when routes are not cached, and when every cached API route
     * carries the switch gate (US-CMP-api-admin-switch).
     *
     * @return void
     */
    public function test_doctor_api_route_gate_passes_when_every_api_route_has_the_gate(): void
    {
        $check = $this->checkNamed($this->runJson('gp247:doctor'), 'api_route_gate');
        $this->assertNotNull($check);
        $this->assertSame('pass', $check['status']);

        $this->withFakeRouteCache(function (): void {
            $this->assertTrue(app()->routesAreCached());
            $this->assertSame('pass', $this->checkNamed($this->runJson('gp247:doctor'), 'api_route_gate')['status']);
        });
    }

    /**
     * A cached API route built without the gate (cache older than the code) is flagged,
     * with the command that fixes it.
     *
     * @return void
     */
    public function test_doctor_api_route_gate_warns_when_cached_api_routes_lack_the_gate(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['api', 'api.extend'])->get('api/front/zz-nogate', fn () => 'ok');

        $this->withFakeRouteCache(function (): void {
            $check = $this->checkNamed($this->runJson('gp247:doctor'), 'api_route_gate');
            $this->assertSame('warn', $check['status']);
            $this->assertStringContainsString('gp247:cache-rebuild', $check['detail']);
        });
    }

    /**
     * A tree with no byte-order marks passes.
     *
     * @return void
     */
    public function test_doctor_reports_no_bom_on_a_clean_tree(): void
    {
        $check = $this->checkNamed($this->runJson('gp247:doctor'), 'file_bom');

        $this->assertNotNull($check, 'doctor must report a file_bom check');
        $this->assertSame('pass', $check['status'], (string) ($check['detail'] ?? ''));
    }

    /**
     * A PHP/Blade file starting with EF BB BF is reported by name.
     *
     * A BOM sits outside the PHP tags, so it is echoed before anything else: it
     * breaks header()/redirect(), corrupts JSON, and puts an invisible character
     * at the top of a rendered view. Nothing about it is visible when reading the
     * file, which is exactly why doctor has to be the one to find it.
     *
     * @return void
     */
    public function test_doctor_flags_a_file_that_starts_with_a_byte_order_mark(): void
    {
        $probe = app_path('GP247/zz_bom_probe.blade.php');
        file_put_contents($probe, chr(0xEF) . chr(0xBB) . chr(0xBF) . '<div>zz probe</div>');

        try {
            $res = $this->runJson('gp247:doctor');
            $check = $this->checkNamed($res, 'file_bom');

            $this->assertSame('warn', $check['status']);
            $this->assertStringContainsString('zz_bom_probe.blade.php', $check['detail']);
            // A BOM must not gate install or CI: it is a defect to clean up, not a
            // reason to refuse to run.
            $this->assertTrue($res['data']['ok']);
            $this->assertSame(0, $res['code']);
        } finally {
            @unlink($probe);
        }
    }

    /**
     * info reports the core version and extension counts.
     *
     * @return void
     */
    public function test_info_reports_versions_and_counts(): void
    {
        $res = $this->runJson('gp247:info');

        $this->assertTrue($res['data']['ok']);
        $this->assertArrayHasKey('core', $res['data']['data']);
        $this->assertArrayHasKey('local', $res['data']['data']['plugins']);
        $this->assertArrayHasKey('active', $res['data']['data']['templates']);
        // gp247/mcp is a core package of S-Cart (null when absent).
        $this->assertArrayHasKey('gp247/mcp', $res['data']['data']['versions']);
        // versions read as the release tag prints them (3.11.2, not 3.11.2.0),
        // the same string as the Server info screen; the four keys stay, absent => null.
        $this->assertSame(['gp247/core', 'gp247/front', 'gp247/shop', 'gp247/mcp'], array_keys($res['data']['data']['versions']));
        foreach ($res['data']['data']['versions'] as $package => $version) {
            if (!\Composer\InstalledVersions::isInstalled($package)) {
                $this->assertNull($version);
                continue;
            }
            $pretty = \Composer\InstalledVersions::getPrettyVersion($package);
            str_starts_with((string) $pretty, 'dev-')
                ? $this->assertStringStartsWith($pretty, $version)
                : $this->assertSame($pretty, $version, "gp247:info must print {$package} as its release tag.");
        }
    }

    /**
     * The orchestrator + maintenance commands are registered.
     *
     * @return void
     */
    public function test_orchestrator_commands_registered(): void
    {
        $names = array_keys(Artisan::all());
        foreach (['gp247:install', 'gp247:update', 'gp247:cache-rebuild', 'gp247:doctor', 'gp247:info'] as $cmd) {
            $this->assertContains($cmd, $names, "{$cmd} must be registered");
        }
    }

    /**
     * Doctor keeps the four legacy rows it shares with the dashboard alerts and,
     * on an installed site, adds the seven security rows from the same classes
     * (US-CLI-004).
     *
     * @return void
     */
    public function test_doctor_keeps_the_shared_legacy_rows_and_adds_the_security_rows(): void
    {
        $res = $this->runJson('gp247:doctor');
        $names = array_column($res['data']['data']['checks'] ?? [], 'name');

        foreach ([
            'encryption_key_dedicated', 'secret_decryptable', 'extension_data_pending', 'extension_assets',
            'app_debug', 'https', 'default_admin_password', 'password_policy', 'mfa_enabled', 'api_exposure', 'support_line',
        ] as $name) {
            $this->assertContains($name, $names, "doctor must report {$name}");
        }
    }

    /**
     * The legacy encryption-key row keeps its exact wording after moving to the
     * shared check class.
     *
     * @return void
     */
    public function test_doctor_encryption_key_row_keeps_its_wording(): void
    {
        config(['gp247-config.security.encryption_key' => '']);
        $row = $this->checkNamed($this->runJson('gp247:doctor'), 'encryption_key_dedicated');
        $this->assertSame('warn', $row['status']);
        $this->assertSame('not set — secrets use APP_KEY; set GP247_ENCRYPTION_KEY to insulate them from an APP_KEY change', $row['detail']);

        config(['gp247-config.security.encryption_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $row = $this->checkNamed($this->runJson('gp247:doctor'), 'encryption_key_dedicated');
        $this->assertSame('pass', $row['status']);
        $this->assertSame('GP247_ENCRYPTION_KEY set', $row['detail']);
    }

    /**
     * A critical dashboard alert is only a `warn` row for doctor: the exit code
     * stays 0 so pipelines and the pre-install gate keep working.
     *
     * @return void
     */
    public function test_doctor_reports_a_critical_alert_as_warn_without_changing_the_exit_code(): void
    {
        config(['app.debug' => true]);

        $res = $this->runJson('gp247:doctor');

        $this->assertSame('warn', $this->checkNamed($res, 'app_debug')['status']);
        $this->assertSame(0, $res['code']);
        $this->assertTrue($res['data']['ok']);
    }

    /**
     * Doctor and the dashboard reach the same verdict on the same site state.
     *
     * @return void
     */
    public function test_doctor_and_the_dashboard_check_agree(): void
    {
        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);
            $alert = (new \GP247\Core\Support\Health\AppDebugCheck)->evaluate();
            $row = $this->checkNamed($this->runJson('gp247:doctor'), 'app_debug');

            $this->assertSame($alert->passed() ? 'pass' : 'warn', $row['status']);
        }
    }
}
