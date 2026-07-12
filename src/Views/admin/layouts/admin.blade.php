{{--
    GP247 modern admin shell layout (ADR-002 / ADR-004).

    This is the full-page layout for Livewire admin screens (used via
    #[Layout('gp247-admin::layouts.admin')]). It replaces the AdminLTE master
    layout: TailAdmin styling, Alpine-driven dark mode + collapsible sidebar,
    `wire:navigate` SPA-like navigation (no pjax). It reuses the brownfield menu
    (`AdminMenu::getListVisible()`) and `gp247_*` helpers without modifying them.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-LW-001
    @aidlc-adr ADR-002, ADR-004

    Variables:
      - $slot (Livewire): rendered page component.
      - $title (string|null): browser/page title.
      - $breadcrumb (array|null): ['name' => ..., 'url' => ...] mid-level crumb.
      - $gp247Menus (array|null): pre-resolved menu tree (override; defaults to AdminMenu).
--}}
@php
    // WHY: allow callers/tests to inject the menu tree; otherwise read the
    // brownfield source of truth (already permission-filtered).
    $gp247Menus = $gp247Menus ?? \GP247\Core\Models\AdminMenu::getListVisible();
    $pageTitle = $title ?? gp247_config_admin('ADMIN_TITLE');
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
            if (localStorage.getItem('gp247-theme') === 'dark') {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>

    {{-- FontAwesome — moved from LTE bundle into AdminShell vendor (Phase 2, MC-002). --}}
    <link rel="stylesheet" href="{{ gp247_file('GP247/Core/AdminShell/vendor/fontawesome-free/css/all.min.css') }}">

    {{-- Self-contained core assets, published from the module to public/ (ADR-004).
         admin.js is loaded here in <head> so its `alpine:init` listener registers
         before Livewire boots Alpine at @livewireScripts. --}}
    <link rel="stylesheet" href="{{ gp247_file('GP247/Core/AdminShell/css/admin.css') }}">
    <script src="{{ gp247_file('GP247/Core/AdminShell/js/admin.js') }}"></script>
    @livewireStyles
    @stack('styles')
</head>

<body>
<div
    x-data="{
        get sidebarOpen() { return $store.gp247.sidebarOpen },
        get sidebarCollapsed() { return $store.gp247.sidebarCollapsed },
        get dark() { return $store.gp247.dark },
    }"
    class="min-h-screen lg:flex"
>
    @include('gp247-admin::partials.sidebar', ['gp247Menus' => $gp247Menus])

    {{-- Backdrop shown when the sidebar is open on small screens. --}}
    <div
        x-show="sidebarOpen"
        x-on:click="$store.gp247.toggleSidebar()"
        class="fixed inset-0 z-20 bg-gray-900/50 lg:hidden"
        x-cloak
    ></div>

    <div class="flex min-h-screen flex-1 flex-col">
        @include('gp247-admin::partials.header')

        <main class="flex-1 p-4 sm:p-6">
            @include('gp247-admin::partials.breadcrumb', ['title' => $pageTitle, 'breadcrumb' => $breadcrumb ?? null])

            {{ $slot }}
        </main>

        {{-- WHY: 'hidden_copyright_footer_admin' (General settings) had no admin
             footer to gate at all — the checkbox was a complete no-op. Content/config
             key ported from the legacy AdminLTE `main-footer` (same
             `gp247_config('hidden_copyright_footer_admin')` call — not
             `gp247_config_admin()` — to match its existing store scoping),
             restyled with Tailwind instead of Bootstrap `float-right`. --}}
        @if (! gp247_config('hidden_copyright_footer_admin'))
            <footer class="flex flex-col items-center justify-between gap-1 border-t border-gray-200 px-4 py-3 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500 sm:flex-row sm:px-6">
                <p>
                    Copyright &copy; {{ date('Y') }}
                    <a href="{{ config('gp247.github') }}" target="_blank" class="hover:text-gray-600 dark:hover:text-gray-300">GP247: {{ config('gp247.name') }}</a>.
                </p>
                <p>
                    <strong>Env</strong> {{ config('app.env') }}
                    &nbsp;&nbsp;
                    <strong>Core</strong> {{ config('gp247.core') }}
                    @if (gp247_composer_get_package_installed()['gp247/s-cart'] ?? false)
                        &nbsp;&nbsp;
                        <a href="https://github.com/gp247net/s-cart" target="_blank" class="hover:text-gray-600 dark:hover:text-gray-300">
                            <strong>S-Cart</strong> ({{ gp247_composer_get_package_installed()['gp247/s-cart'] }})
                        </a>
                    @endif
                </p>
            </footer>
        @endif
    </div>

    {{-- Global, event-based UI feedback (ADR-005). --}}
    <x-gp247::toast />

    {{-- Livewire request loading overlay. --}}
    <div wire:loading.flex wire:loading.delay
        class="fixed inset-0 z-40 hidden items-center justify-center bg-gray-900/20">
        <i class="fas fa-spinner fa-spin fa-2x text-blue-600"></i>
    </div>
</div>

@livewireScripts
@stack('scripts')
</body>
</html>
