{{--
    GP247 plain (non-Livewire) admin layout (ADR-002 / ADR-004).

    Same chrome as layouts/admin.blade.php (sidebar, header, breadcrumb, toast,
    dark-mode) but uses @yield('main') instead of {{ $slot }} so traditional
    controller-rendered views can @extends this file.

    Usage: @extends('gp247-admin::layouts.plain') + @section('main') ... @endsection

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-LW-001
    @aidlc-adr ADR-002, ADR-004
--}}
@php
    $gp247Menus = $gp247Menus ?? \GP247\Core\Models\AdminMenu::getListVisible();
    $pageTitle   = $title ?? gp247_config_admin('ADMIN_TITLE');
@endphp
<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ gp247_config_admin('ADMIN_TITLE') }} | {{ $pageTitle }}</title>
    <link rel="icon" href="{{ gp247_file(gp247_store_info('icon')) }}" type="image/png" sizes="16x16">

    {{-- WHY: apply the persisted theme before first paint to avoid a light/dark flash. --}}
    <script>
        try {
            if (localStorage.getItem('gp247-theme') === 'dark') document.documentElement.classList.add('dark');
        } catch (e) {}
    </script>

    <link rel="stylesheet" href="{{ gp247_file('GP247/Core/AdminShell/vendor/fontawesome-free/css/all.min.css') }}">
    <link rel="stylesheet" href="{{ gp247_file('GP247/Core/AdminShell/css/admin.css') }}">
    <script src="{{ gp247_file('GP247/Core/AdminShell/js/admin.js') }}"></script>
    @livewireStyles
    @stack('styles')
</head>

<body>
<div
    x-data="{
        get sidebarOpen()      { return $store.gp247.sidebarOpen },
        get sidebarCollapsed() { return $store.gp247.sidebarCollapsed },
        get dark()             { return $store.gp247.dark },
    }"
    class="min-h-screen lg:flex"
>
    @include('gp247-admin::partials.sidebar', ['gp247Menus' => $gp247Menus])

    <div x-show="sidebarOpen" x-on:click="$store.gp247.toggleSidebar()"
         class="fixed inset-0 z-20 bg-gray-900/50 lg:hidden" x-cloak></div>

    <div class="flex min-h-screen flex-1 flex-col">
        @include('gp247-admin::partials.header')

        <main class="flex-1 p-4 sm:p-6">
            @include('gp247-admin::partials.breadcrumb', [
                'title'      => $pageTitle,
                'breadcrumb' => $breadcrumb ?? null,
            ])

            @yield('main')
        </main>
    </div>

    <x-gp247::toast />

    {{-- Page-level loading overlay (shown via JS: document.getElementById('gp247-page-loading').style.display). --}}
    <div id="gp247-page-loading"
         class="fixed inset-0 z-40 hidden items-center justify-center bg-gray-900/20">
        <i class="fas fa-spinner fa-spin fa-2x text-blue-600"></i>
    </div>
</div>

@livewireScripts
@stack('scripts')
</body>
</html>
