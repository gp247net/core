<?php

namespace GP247\Core\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Answers every web request of a site that is not installed yet with a short notice
 * telling the owner to run `php artisan gp247:install`.
 *
 * Registered as the first global middleware only while the install marker is missing,
 * so it runs before sessions, routing, Livewire and the database: a fresh
 * `composer create-project` site with database-backed cache/sessions and an empty
 * database shows the notice instead of a server error. It deliberately uses nothing
 * but the `local` disk and the request — no view, cache, session, database or
 * `gp247_*` helper (those are only available after install).
 *
 * The notice is a static English page (no language data exists before install) and
 * never includes paths, versions or environment details.
 *
 * @aidlc-unit installer-deploy
 * @aidlc-story US-DEP-not-installed-notice
 * @aidlc-adr installer-deploy_remove-web-installer
 */
class NotInstalledNotice
{
    /** File on the `local` disk that marks a finished install (same check as everywhere else). */
    public const MARKER = 'gp247-installed.txt';

    /** Seconds a client or crawler should wait before trying again. */
    private const RETRY_AFTER_SECONDS = 300;

    private const GUIDE_URL = 'https://github.com/gp247net/gp247-docs/blob/main/install-upgrade/install.md';

    private const MESSAGE = 'This site is not installed yet. Run php artisan gp247:install in the project folder.';

    /**
     * Let the request through on an installed site; otherwise answer with the notice.
     *
     * @param Request $request Incoming request.
     * @param Closure $next    Next middleware.
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (self::installed()) {
            return $next($request);
        }

        return $this->wantsJson($request) ? $this->jsonNotice() : $this->htmlNotice();
    }

    /**
     * Whether the install marker exists.
     *
     * @return bool
     */
    public static function installed(): bool
    {
        return Storage::disk('local')->exists(self::MARKER);
    }

    /**
     * API clients (and anything asking for JSON) get a machine-readable answer.
     *
     * @param Request $request Incoming request.
     * @return bool
     */
    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api', 'api/*');
    }

    /**
     * Headers shared by both notice formats.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Retry-After' => (string) self::RETRY_AFTER_SECONDS,
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }

    /**
     * @return JsonResponse
     */
    private function jsonNotice(): JsonResponse
    {
        return new JsonResponse(['code' => 'not_installed', 'message' => self::MESSAGE], 503, $this->headers());
    }

    /**
     * @return Response
     */
    private function htmlNotice(): Response
    {
        $guide = self::GUIDE_URL;
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Not installed yet</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#1e293b;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
main{max-width:560px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:32px}
h1{font-size:22px;margin:0 0 12px}
p{line-height:1.6;margin:0 0 12px}
code{display:block;background:#0f172a;color:#e2e8f0;padding:12px 16px;border-radius:8px;font-size:15px;margin:0 0 12px;overflow-x:auto}
a{color:#2563eb}
@media (prefers-color-scheme:dark){body{background:#0f172a;color:#e2e8f0}main{background:#1e293b;border-color:#334155}a{color:#93c5fd}}
</style>
</head>
<body>
<main>
<h1>This site is not installed yet</h1>
<p>Open a terminal in the project folder and run:</p>
<code>php artisan gp247:install</code>
<p>The site opens normally once the installation has finished.</p>
<p>Step-by-step guide, including hosting without a command line: <a href="{$guide}" rel="noopener">Installing GP247 and S-Cart</a>.</p>
</main>
</body>
</html>
HTML;

        return new Response($html, 503, ['Content-Type' => 'text/html; charset=UTF-8'] + $this->headers());
    }
}
