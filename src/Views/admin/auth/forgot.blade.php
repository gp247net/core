{{--
    Modern TailAdmin forgot-password (US-AUI-007). Presentation only: posts to the
    legacy `admin.post_forgot` endpoint (SendsPasswordResetEmails), backend mail
    flow unchanged.

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-007
    @aidlc-adr ADR-002
--}}
@extends('gp247-admin::layouts.auth')

@php
    $input = 'w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100';
    // Cutover (PA-1): the legacy login URL now renders the modern login screen.
    $loginUrl = gp247_route_admin('admin.login');
@endphp

@section('content')
    <h1 class="mb-6 text-center text-xl font-semibold text-gray-800 dark:text-gray-100">
        {{ gp247_language_render('admin.password_forgot') }}
    </h1>

    @if (session('status'))
        <p class="mb-4 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700 dark:bg-green-900/20 dark:text-green-300">
            {{ session('status') }}
        </p>
    @endif

    <form action="{{ gp247_route_admin('admin.post_forgot') }}" method="post" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ gp247_language_render('admin.user.email') }}
            </label>
            <div class="relative">
                <i class="fas fa-envelope pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autofocus
                    class="{{ $input }} pl-9 {{ $errors->has('email') ? 'border-red-500' : 'border-gray-300 dark:border-gray-600' }}">
            </div>
            @error('email')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>

        <button type="submit"
            class="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1">
            {{ gp247_language_render('action.submit') }}
        </button>
    </form>

    <p class="mt-4 text-center text-sm">
        <a href="{{ $loginUrl }}" class="font-medium text-blue-600 hover:underline dark:text-blue-400">
            <i class="fas fa-angle-left"></i> {{ gp247_language_render('admin.user.login') }}
        </a>
    </p>
@endsection
