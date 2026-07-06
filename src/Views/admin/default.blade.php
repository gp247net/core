{{--
    Generic admin landing/placeholder (US-AUI-008 gap) — ported to the AdminShell
    TailAdmin plain layout.

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-008
    @aidlc-adr ADR-002
--}}
@extends('gp247-admin::layouts.plain')

@section('main')
<div class="flex min-h-[40vh] items-center justify-center">
    <h1 class="text-center text-2xl font-semibold text-gray-700 dark:text-gray-200">
        {{ gp247_language_render('admin.welcome_dasdboard') }}
    </h1>
</div>
@endsection
