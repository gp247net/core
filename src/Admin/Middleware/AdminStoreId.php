<?php

namespace GP247\Core\Admin\Middleware;

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
            session(['adminStoreId' => GP247_ID_ROOT]);
        } else {
            session()->forget('adminStoreId');
        }
        return $next($request);
    }
}
