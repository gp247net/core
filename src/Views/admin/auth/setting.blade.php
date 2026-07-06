{{--
    Account settings (US-AUI-008 gap) — ported from AdminLTE to the AdminShell
    TailAdmin plain layout. Presentation only: the form still posts to the legacy
    admin.post_setting endpoint (LoginController@putSetting), so validation,
    sanitize and the administrator-only email rule are unchanged.

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-008
    @aidlc-adr ADR-002

    Variables: $user, $url_action, $roles, $permission, $title_description.
--}}
@extends('gp247-admin::layouts.plain')

@php
    $input = 'w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100';
    $base = ' border-gray-300 dark:border-gray-600';
    $err = ' border-red-500';
    $isAdministrator = admin()->user() && admin()->user()->isRole('administrator');
    $roleIds = $user ? $user->roles->pluck('id')->toArray() : [];
    $permIds = $user ? $user->permissions->pluck('id')->toArray() : [];
@endphp

@section('main')
<div class="mx-auto max-w-3xl">
    <x-gp247::card :title="gp247_language_render('admin.user.setting')">
        <x-slot:actions>
            <x-gp247::button href="{{ gp247_route_admin('admin_user.index') }}" variant="secondary" size="sm">
                <i class="fas fa-list"></i> {{ gp247_language_render('admin.back_list') }}
            </x-gp247::button>
        </x-slot:actions>

        <form action="{{ $url_action }}" method="post" enctype="multipart/form-data" class="space-y-5">
            @csrf

            <div>
                <label for="name" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.user.name') }}</label>
                <input id="name" name="name" type="text" value="{{ old('name', $user['name'] ?? '') }}"
                    class="{{ $input }}{{ $errors->has('name') ? $err : $base }}">
                @error('name')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="username" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.user.user_name') }}</label>
                <input id="username" type="text" value="{{ $user['username'] ?? '' }}" disabled
                    class="{{ $input }}{{ $base }} cursor-not-allowed bg-gray-100 dark:bg-gray-800">
            </div>

            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.user.email') }}</label>
                @if ($isAdministrator)
                    <input id="email" name="email" type="text" value="{{ old('email', $user['email'] ?? '') }}"
                        class="{{ $input }}{{ $errors->has('email') ? $err : $base }}">
                @else
                    <input id="email" type="text" value="{{ $user['email'] ?? '' }}" disabled
                        class="{{ $input }}{{ $base }} cursor-not-allowed bg-gray-100 dark:bg-gray-800">
                @endif
                @error('email')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            </div>

            <x-gp247::media-input name="avatar" type="avatar" :value="old('avatar', $user['avatar'] ?? '')"
                :label="gp247_language_render('admin.user.avatar')" :error="$errors->first('avatar')" />

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.user.password') }}</label>
                <input id="password" name="password" type="password"
                    class="{{ $input }}{{ $errors->has('password') ? $err : $base }}">
                @error('password')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @else
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ gp247_language_render('admin.user.keep_password') }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.user.password_confirmation') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                    class="{{ $input }}{{ $errors->has('password_confirmation') ? $err : $base }}">
                @error('password_confirmation')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            </div>

            @if ($roleIds)
                <div>
                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.user.select_roles') }}</span>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($roleIds as $role)
                            <x-gp247::badge color="blue">{{ $roles[$role] ?? '' }}</x-gp247::badge>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($permIds)
                <div>
                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.user.select_permission') }}</span>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($permIds as $p)
                            <x-gp247::badge color="blue">{{ $permission[$p] ?? '' }}</x-gp247::badge>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (function_exists('mfa_get_guard_config') && mfa_get_guard_config('admin')['enabled'])
                <div>
                    <a href="{{ gp247_route_front('mfa.manage', ['guard' => 'admin']) }}"
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                        <i class="fas fa-user-shield"></i> {{ gp247_language_render('Plugins/MFA::lang.admin_title') }}
                    </a>
                </div>
            @endif

            <div class="flex items-center gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                <button type="submit"
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    {{ gp247_language_render('action.submit') }}
                </button>
                <button type="reset"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ gp247_language_render('action.reset') }}
                </button>
            </div>
        </form>
    </x-gp247::card>
</div>
@endsection
