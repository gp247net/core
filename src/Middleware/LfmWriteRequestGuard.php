<?php

namespace GP247\Core\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Lets a file manager write action (delete, rename, new folder, move, resize, crop)
 * run only for a same-origin AJAX request.
 *
 * The file manager library sends every action as GET, which Laravel's CSRF check
 * skips, so a link or <img> on another site could otherwise trigger it with an
 * admin's session. The library's own jQuery adds X-Requested-With to its same-origin
 * calls; another site cannot add that header without a CORS preflight, and the
 * Sec-Fetch-Site header (when the browser sends it) must say same-origin — "same-site"
 * is refused because tenants run on sibling subdomains.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-lfm-write-forgery-guard
 * @aidlc-adr admin-shell_lfm-write-request-guard
 */
class LfmWriteRequestGuard
{
    /**
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');

        if ($request->headers->get('X-Requested-With') !== 'XMLHttpRequest'
            || ($fetchSite !== null && $fetchSite !== 'same-origin')) {
            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
