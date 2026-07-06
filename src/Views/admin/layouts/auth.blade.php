{{--
    GP247 modern admin auth layout (US-AUI-007, ADR-002/004).

    Minimal guest layout for the TailAdmin login / forgot / reset screens: no
    sidebar, header or Livewire — just the self-contained admin CSS + FontAwesome
    and a centered card. Respects the persisted dark theme (pre-paint) like the
    main admin layout. Forms inside post to the existing legacy auth endpoints.

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-007
    @aidlc-adr ADR-002, ADR-004

    Variables:
      - $title (string|null): screen title.
--}}
@php
    $pageTitle = $title ?? gp247_language_render('admin.login');
@endphp
<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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

    <link rel="stylesheet" href="{{ gp247_file('GP247/Core/AdminShell/vendor/fontawesome-free/css/all.min.css') }}">
    <link rel="stylesheet" href="{{ gp247_file('GP247/Core/AdminShell/css/admin.css') }}">
    @stack('styles')
</head>

<body class="bg-gray-100 dark:bg-gray-900">
    <main class="flex min-h-screen items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div class="mb-6 text-center">
                <a href="{{ gp247_route_admin('home') }}" class="inline-flex">
                    <img src="{{ gp247_file(gp247_store_info('logo')) }}" alt="logo" class="mx-auto h-12 w-auto max-w-full">
                </a>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-8">
                @yield('content')
            </div>
        </div>
    </main>
    @stack('scripts')
</body>
</html>
