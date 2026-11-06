<?php

namespace GP247\Core\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class Authenticate
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $redirectTo = gp247_route_admin('admin.login');
        if (Auth::guard('admin')->guest() && !$this->shouldPassThrough($request)) {
            return redirect()->guest($redirectTo);
        }
        if (Auth::guard('admin')->check() && $this->sessionEndedByRestore($request)) {
            // A post-incident restore ended every admin session signed in before it.
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest($redirectTo);
        }
        if ($this->mustChangePassword($request)) {
            return redirect(gp247_route_admin('admin.setting'))
                ->with('error', gp247_language_render('admin.password_change_required'));
        }

        return $next($request);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return bool The session signed in before the last post-incident restore.
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    protected function sessionEndedByRestore($request): bool
    {
        $at = $request->hasSession() ? $request->session()->get(\GP247\Core\Backup\Application\PostIncidentChecklist::SESSION_AUTH_AT) : null;

        return \GP247\Core\Backup\Application\PostIncidentChecklist::sessionEnded(is_numeric($at) ? (int) $at : null);
    }

    /**
     * An admin whose account was flagged by a post-incident restore reaches only the page that
     * changes the password (and sign-out) until a new password is saved.
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    protected function mustChangePassword($request): bool
    {
        $user = Auth::guard('admin')->user();
        if ($user === null || empty($user->password_change_required)) {
            return false;
        }

        return !in_array($request->route()?->getName(), ['admin.setting', 'admin.post_setting', 'admin.logout'], true);
    }

    /**
     * Determine if the request has a URI that should pass through verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function shouldPassThrough($request)
    {
        $routeName = $request->route()->getName();
        $excepts = [
            'admin.login',
            'admin.post_login',
            'admin.logout',
            'admin.forgot',
            'admin.post_forgot',
            'admin.register',
            'admin.password_reset',
            'admin.password_request',
        ];
        return in_array($routeName, $excepts);
    }
}
