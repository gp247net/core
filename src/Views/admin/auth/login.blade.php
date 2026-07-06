{{--
    Modern TailAdmin admin login (US-AUI-007). Presentation only: the form posts
    to the legacy `admin.post_login` endpoint, so validation/guard/throttle/CSRF
    are unchanged. Fields mirror the legacy screen (username/password/remember).

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-007
    @aidlc-adr ADR-002
--}}
@extends('gp247-admin::layouts.auth')

@php
    $input = 'w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100';
    $forgotEnabled = (bool) config('gp247-config.admin.forgot_password');
    // Cutover (PA-1): the legacy forgot URL now renders this same modern screen.
    $forgotUrl = gp247_route_admin('admin.forgot');
@endphp

@section('content')
    <h1 class="mb-6 text-center text-xl font-semibold text-gray-800 dark:text-gray-100">
        {{ gp247_language_render('admin.login') }}
    </h1>

    <form action="{{ gp247_route_admin('admin.post_login') }}" method="post" class="space-y-4">
        @csrf

        <div>
            <label for="username" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ gp247_language_render('admin.user.username') }}
            </label>
            <div class="relative">
                <i class="fas fa-user pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
                <input id="username" name="username" type="text" value="{{ old('username') }}" autofocus
                    class="{{ $input }} pl-9 {{ $errors->has('username') ? 'border-red-500' : 'border-gray-300 dark:border-gray-600' }}">
            </div>
            @error('username')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ gp247_language_render('admin.user.password') }}
            </label>
            <div class="relative">
                <i class="fas fa-lock pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
                <input id="password" name="password" type="password"
                    class="{{ $input }} pl-9 {{ $errors->has('password') ? 'border-red-500' : 'border-gray-300 dark:border-gray-600' }}">
            </div>
            @error('password')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>

        <div class="flex items-center justify-between">
            <label class="flex cursor-pointer select-none items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
                <x-gp247::checkbox name="remember" value="1" :checked="(bool) old('remember')" />
                {{ gp247_language_render('admin.user.remember_me') }}
            </label>
            @if ($forgotEnabled)
                <a href="{{ $forgotUrl }}" class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                    {{ gp247_language_render('admin.password_forgot') }}
                </a>
            @endif
        </div>

        <button type="submit"
            class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1">
            {{ gp247_language_render('admin.user.login') }}
        </button>
    </form>
@endsection
