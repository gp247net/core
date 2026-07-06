{{--
    Friendly "access denied" screen (US-UI-008): rendered by
    AdminShellServiceProvider's renderable() handler in place of the raw
    exception page whenever a full-page GET hits an AuthorizationException
    (e.g. mount()'s authorizeView() denial). Keeps the normal admin chrome
    (sidebar/header) since the visitor is a logged-in admin, just missing a
    permission — this is not a login/guest screen.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-008
    @aidlc-adr ADR-001, ADR-005
--}}
@extends('gp247-admin::layouts.plain')

@section('main')
    <div class="flex flex-col items-center justify-center rounded-xl border border-gray-200 bg-white px-6 py-16 text-center shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <i class="fas fa-lock mb-4 text-4xl text-amber-500"></i>
        <h1 class="mb-2 text-xl font-semibold text-gray-800 dark:text-gray-100">
            {{ gp247_language_render('admin.core.access_denied_title') }}
        </h1>
        <p class="mb-6 max-w-md text-sm text-gray-500 dark:text-gray-400">
            {{ gp247_language_render('admin.core.access_denied_message') }}
        </p>
        <x-gp247::button href="{{ gp247_route_admin('admin.home') }}">
            {{ gp247_language_render('admin.core.back_to_dashboard') }}
        </x-gp247::button>
    </div>
@endsection
