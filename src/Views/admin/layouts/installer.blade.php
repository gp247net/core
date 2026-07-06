{{--
    GP247 installer layout — standalone, no GP247 helpers required.

    Uses Tailwind CDN so this page renders correctly before the platform is
    installed and admin assets have not been published yet. No gp247_file(),
    gp247_config_admin(), or gp247_store_info() calls are permitted here.

    @aidlc-unit installer-deploy
    @aidlc-story US-DEP-001
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>GP247 — Install</title>
    {{-- WHY: use CDN so the installer works before `php artisan vendor:publish`. --}}
    <script src="https://cdn.tailwindcss.com"></script>
    @livewireStyles
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-lg">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">GP247 Installer</h1>
            <p class="text-gray-500 text-sm mt-1">Web installation wizard</p>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 sm:p-8">
            {{ $slot }}
        </div>
    </div>

    @livewireScripts
</body>
</html>
