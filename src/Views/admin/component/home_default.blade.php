{{--
    Dashboard welcome block (ADR-005/007) — admin_home_layout view
    "gp247-core::component.home_default". Shows a welcome message, plus quick
    links to get started when no shop package is installed. Self-contained:
    resolves $hasShop here (core stays standalone, so this is a plain
    class_exists guard, not the shared shop helper) instead of receiving it
    from Dashboard::blocks() (vendor/gp247/core), which now only renders
    whichever blocks are configured.

    @aidlc-unit admin-shell
    @aidlc-story US-LW-001, US-UI-009
    @aidlc-adr ADR-005, ADR-007
--}}
@php
    $hasShop = class_exists(\GP247\Shop\Admin\Models\AdminOrder::class)
        || class_exists(\GP247\Shop\Admin\Models\AdminProduct::class)
        || class_exists(\GP247\Shop\Admin\Models\AdminCustomer::class);
@endphp
<x-gp247::card>
    <div class="flex flex-col items-center gap-2 py-6 text-center">
        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
            <i class="fas fa-gauge-high text-lg"></i>
        </span>
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.dashboard.welcome') }}</h3>

        @unless ($hasShop)
            <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.dashboard.no_shop') }}</p>
            <div class="mt-2 flex flex-wrap justify-center gap-2">
                @if (Route::has('admin_store.index'))
                    <x-gp247::button href="{{ gp247_route_admin('admin_store.index') }}" wire:navigate size="sm" variant="secondary">
                        <i class="fas fa-info-circle"></i> {{ gp247_language_render('admin.store.title') }}
                    </x-gp247::button>
                @endif
                @if (Route::has('admin_config.index'))
                    <x-gp247::button href="{{ gp247_route_admin('admin_config.index') }}" wire:navigate size="sm" variant="secondary">
                        <i class="fas fa-sliders-h"></i> {{ gp247_language_render('admin.core.cfg_title') }}
                    </x-gp247::button>
                @endif
                @if (Route::has('admin_user.index'))
                    <x-gp247::button href="{{ gp247_route_admin('admin_user.index') }}" wire:navigate size="sm" variant="secondary">
                        <i class="fas fa-users"></i> {{ gp247_language_render('admin.user.title') }}
                    </x-gp247::button>
                @endif
            </div>
        @endunless
    </div>
</x-gp247::card>
