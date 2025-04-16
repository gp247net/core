<?php

namespace GP247\Core\Middleware;

use GP247\Core\Models\AdminLog;
use Illuminate\Http\Request;

class LogOperation
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        if ($this->shouldLogOperation($request)) {
            //Default except password for security
            $adminLogExcept = ['password', 'password_confirmation'];

            $adminLogExcept = array_merge($adminLogExcept, explode(',', config('gp247-config.admin.admin_log_except')));
            $log = [
                'user_id' => admin()->user()->id,
                'path' => substr($request->path(), 0, 255),
                'method' => $request->method(),
                'ip' => $request->getClientIp(),
                'user_agent' => $request->header('User-Agent'),
                'input' => json_encode($request->except($adminLogExcept)),
            ];

            try {
                AdminLog::create($log);
            } catch (\Throwable $exception) {
                // pass
            }
        }

        return $next($request);
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    protected function shouldLogOperation(Request $request)
    {
        return config('gp247-config.admin.admin_log')
        && !$this->inExceptArray($request)
        && admin()->user();
    }

    /**
     * Determine if the request has a URI that should pass through CSRF verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function inExceptArray($request)
    {
        foreach (explode(',', gp247_config_global('ADMIN_LOG_EXP','')) as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }
            if ($request->path() == $except) {
                return true;
            }
        }

        return false;
    }
}
