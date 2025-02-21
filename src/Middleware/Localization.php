<?php

namespace GP247\Core\Middleware;

use GP247\Core\Models\AdminLanguage;
use Closure;
use Session;

class Localization
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
        //Set language
        $languages = AdminLanguage::getListActive();
        if (!Session::has('locale')) {
            $detectLocale = gp247_store_info('language') ?? config('app.locale');
        } else {
            $detectLocale = session('locale');
        }
        $currentLocale = array_key_exists($detectLocale, $languages->toArray()) ? $detectLocale : $languages->first()->code;
        session(['locale' => $currentLocale, 'locale_id' => $languages[$currentLocale]['id']]);
        app()->setLocale($currentLocale);
        //End language
        return $next($request);
    }
}
