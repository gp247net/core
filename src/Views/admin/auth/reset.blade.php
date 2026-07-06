{{--
    Modern TailAdmin reset-password (US-AUI-007). Presentation only: posts to the
    legacy `admin.password_request` endpoint (ResetsPasswords), backend unchanged.
    Mirrors the legacy fields: hidden token + email, password, confirmation.

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-007
    @aidlc-adr ADR-002

    Variables: $token (string|null).
--}}
@extends('gp247-admin::layouts.auth')

@php
    $input = 'w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100';
    // Cutover (PA-1): the legacy login URL now renders the modern login screen.
    $loginUrl = gp247_route_admin('admin.login');
@endphp

@section('content')
    <h1 class="mb-6 text-center text-xl font-semibold text-gray-800 dark:text-gray-100">
        {{ gp247_language_render('admin.password_reset') }}
    </h1>

    <form action="{{ gp247_route_admin('admin.password_request') }}" method="post" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ gp247_language_render('admin.user.email') }}
            </label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autofocus
                class="{{ $input }} {{ $errors->has('email') ? 'border-red-500' : 'border-gray-300 dark:border-gray-600' }}">
            @error('email')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ gp247_language_render('admin.user.password') }}
            </label>
            <input id="password" name="password" type="password"
                class="{{ $input }} {{ $errors->has('password') ? 'border-red-500' : 'border-gray-300 dark:border-gray-600' }}">
            @error('password')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ gp247_language_render('admin.user.password_confirmation') }}
            </label>
            <input id="password_confirmation" name="password_confirmation" type="password"
                class="{{ $input }} border-gray-300 dark:border-gray-600">
            @error('password_confirmation')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
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
