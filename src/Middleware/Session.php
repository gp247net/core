<?php

namespace GP247\Core\Middleware;

use Illuminate\Http\Request;

class Session
{
    public function handle(Request $request, \Closure $next)
    {
        $path = '/' . trim(GP247_ADMIN_PREFIX, '/');

        config(['session.path' => $path]);

        return $next($request);
    }
}
