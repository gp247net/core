<?php

namespace GP247\Core\Middleware;

use Closure;

class AdminStoreId
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (admin()->user()) {
            // WHY: default ROOT (no query) unless the Pro edition registers a
            // store_resolver; the resolver runs its own permission check before
            // the value reaches the session (single seam — ADR
            // multi-store_admin-store-scope-seam).
            session(['adminStoreId' => gp247_admin_store_resolve()]);
        } else {
            session()->forget('adminStoreId');
        }
        return $next($request);
    }
}
