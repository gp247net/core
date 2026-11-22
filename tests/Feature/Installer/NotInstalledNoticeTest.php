<?php

namespace GP247\Core\Tests\Feature\Installer;

use GP247\Core\Middleware\NotInstalledNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use GP247\Core\Tests\TestCase;

/**
 * Someone who opens a site that is not installed yet sees how to install it (run
 * `php artisan gp247:install`) instead of an error page, and there is no page on the web
 * that installs the platform.
 *
 * @aidlc-unit installer-deploy
 * @aidlc-story US-DEP-not-installed-notice
 * @aidlc-adr installer-deploy_remove-web-installer
 */
class NotInstalledNoticeTest extends TestCase
{
    private const MARKER = 'gp247-installed.txt';

    private function handle(Request $request, bool $installed): \Symfony\Component\HttpFoundation\Response
    {
        $disk = Storage::fake('local');
        if ($installed) {
            $disk->put(self::MARKER, 'installed');
        }

        return (new NotInstalledNotice())->handle($request, fn () => response('the site itself'));
    }

    public function test_a_browser_on_a_site_not_installed_yet_is_told_to_run_the_install_command(): void
    {
        $response = $this->handle(Request::create('/', 'GET'), false);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('php artisan gp247:install', $response->getContent());
        $this->assertStringContainsString('not installed', $response->getContent());
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertNotEmpty($response->headers->get('Retry-After'), 'a 503 tells crawlers when to come back');
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    }

    public function test_the_notice_does_not_reveal_where_the_site_lives_or_how_it_is_configured(): void
    {
        $body = $this->handle(Request::create('/admin', 'GET'), false)->getContent();

        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString(str_replace('\\', '/', base_path()), $body);
        $this->assertStringNotContainsString((string) config('app.key'), $body);
        $this->assertStringNotContainsString('DB_', $body);
    }

    public function test_an_api_client_on_a_site_not_installed_yet_gets_a_json_answer(): void
    {
        $byHeader = $this->handle(Request::create('/anything', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']), false);
        $byPath = $this->handle(Request::create('/api/v2/products', 'GET'), false);

        foreach ([$byHeader, $byPath] as $response) {
            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame('not_installed', json_decode($response->getContent(), true)['code'] ?? null);
            $this->assertStringContainsString('gp247:install', json_decode($response->getContent(), true)['message'] ?? '');
        }
    }

    public function test_an_installed_site_is_served_as_usual(): void
    {
        $response = $this->handle(Request::create('/', 'GET'), true);

        $this->assertSame('the site itself', $response->getContent());
    }

    public function test_there_is_no_web_page_that_installs_the_platform(): void
    {
        $this->assertFalse(Route::has('gp247.install'), 'the web installer route is gone');
        // WHY file check, not class_exists(): an optimized Composer classmap keeps the old
        // entry until `composer dump-autoload`, and class_exists() would try to include it.
        $this->assertFileDoesNotExist(base_path('vendor/gp247/core/src/AdminShell/Http/Livewire/InstallerWizard.php'), 'the web installer component is gone');
        $this->assertFalse(
            collect(Route::getRoutes()->getRoutes())->contains(fn ($route) => $route->uri() === 'install'),
            'no route answers /install'
        );
    }
}
