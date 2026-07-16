{{--
    GP247 admin top bar (ADR-002).

    Holds the mobile sidebar toggle, the admin language switcher, the dark-mode
    switch (Alpine `gp247` store) and a user menu with logout. Reuses the existing
    `admin.locale` / `admin.logout` routes and the authenticated admin guard — no
    auth changes.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-LW-001
    @aidlc-adr ADR-002
--}}
@php
    $adminUser = admin()->user();
    $adminAvatar = ($adminUser && $adminUser->avatar)
        ? gp247_file($adminUser->avatar)
        : gp247_file('GP247/Core/avatar/user.jpg');
    // WHY: reuse the brownfield locale switch (session('locale') + admin.locale
    // route) so the new shell changes admin language exactly like the old header.
    $languages = gp247_language_all();
    $currentLocale = session('locale') ?? app()->getLocale();
    $currentLanguage = $languages[$currentLocale] ?? null;
    // WHY: front/shop are optional (core is standalone) — only link the storefront
    // when its route is actually registered.
    $hasStorefront = \Illuminate\Support\Facades\Route::has('front.home');
@endphp

<header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-gray-200 bg-white px-4 dark:border-gray-700 dark:bg-gray-800 sm:px-6">
    <button type="button" x-on:click="$store.gp247.toggleSidebar()"
        class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
        aria-label="{{ gp247_language_render('admin.toggle_sidebar') }}">
        <i class="fas fa-bars"></i>
    </button>

    <div class="flex items-center gap-2">
        @if ($hasStorefront)
            <a href="{{ gp247_route_front('front.home') }}" target="_blank" rel="noopener"
                class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                title="{{ gp247_language_render('admin.go_to_shop') }}">
                <i class="fas fa-store-alt"></i>
            </a>
        @endif

        @if ($languages && count($languages) > 1)
            <div class="relative" x-data="{ open: false }">
                <button type="button" x-on:click="open = ! open"
                    class="flex items-center gap-1 rounded-lg px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                    aria-label="{{ gp247_language_render('admin.language.title') }}">
                    @if ($currentLanguage)
                        <img src="{{ gp247_file($currentLanguage['icon']) }}" alt="{{ $currentLanguage['name'] }}" class="h-5 w-7 rounded object-cover">
                    @else
                        <i class="fas fa-globe"></i>
                    @endif
                    <i class="fas fa-angle-down text-xs"></i>
                </button>

                <div x-show="open" x-on:click.outside="open = false" x-cloak
                    class="absolute right-0 mt-2 w-44 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                    @foreach ($languages as $code => $language)
                        <a href="{{ gp247_route_admin('admin.locale', ['code' => $code]) }}"
                            class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700 {{ $code === $currentLocale ? 'bg-gray-50 font-semibold dark:bg-gray-700/50' : '' }}">
                            <img src="{{ gp247_file($language['icon']) }}" alt="{{ $language['name'] }}" class="h-5 w-7 rounded object-cover">
                            {{ $language['name'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Admin notifications (mirrors the legacy AdminLTE notice block,
             AdminNotice model). Alpine dropdown, TailAdmin styling. --}}
        @php
            $noticeCount = \GP247\Core\Models\AdminNotice::getCountNoticeNew();
            $noticeList = \GP247\Core\Models\AdminNotice::getTopNotice();
        @endphp
        <div class="relative" x-data="{ open: false }">
            <button type="button" x-on:click="open = ! open"
                class="relative rounded-lg p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                aria-label="{{ gp247_language_render('admin.notice.title') }}">
                <i class="far fa-bell"></i>
                @if ($noticeCount)
                    <span class="absolute -right-0.5 -top-0.5 inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-none text-white">{{ $noticeCount > 99 ? '99+' : $noticeCount }}</span>
                @endif
            </button>

            <div x-show="open" x-on:click.outside="open = false" x-cloak
                class="absolute right-0 mt-2 w-80 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-700">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.notice.title') }}</span>
                    @if ($noticeList->count())
                        <a href="{{ gp247_route_admin('admin_notice.mark_read') }}"
                            class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">{{ gp247_language_render('admin.notice.mark_read') }}</a>
                    @endif
                </div>

                @if ($noticeList->count())
                    <div class="max-h-80 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
                        @foreach ($noticeList as $notice)
                            <a href="{{ gp247_route_admin('admin_notice.url', ['type' => $notice->type, 'typeId' => $notice->type_id]) }}"
                                class="flex gap-3 px-4 py-3 text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50 {{ $notice->status ? '' : 'bg-blue-50/60 dark:bg-blue-900/10' }}">
                                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                    @if (in_array($notice->type, ['gp247_order_created', 'gp247_order_success', 'gp247_order_update_status']))
                                        <i class="fas fa-cart-plus"></i>
                                    @elseif (in_array($notice->type, ['gp247_customer_created']))
                                        <i class="fas fa-users"></i>
                                    @else
                                        <i class="far fa-bell"></i>
                                    @endif
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-gray-700 dark:text-gray-200">{{ gp247_content_render($notice->content) }}</span>
                                    <span class="mt-0.5 block text-xs text-gray-400 dark:text-gray-500">[{{ $notice->admin->name ?? $notice->admin_id }}] {{ gp247_datetime_to_date($notice->created_at, 'Y-m-d H:i:s') }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                    @php
                        // Strangler: prefer the modern full-page notice list when registered,
                        // else fall back to the legacy AdminNotice index route.
                        $noticeIndexUrl = \Illuminate\Support\Facades\Route::has('admin_notice.index')
                            ? gp247_route_admin('admin_notice.index')
                            : gp247_route_admin('admin_notice.index');
                    @endphp
                    <a href="{{ $noticeIndexUrl }}"
                        class="block border-t border-gray-100 px-4 py-2.5 text-center text-sm font-medium text-blue-600 hover:bg-gray-50 dark:border-gray-700 dark:text-blue-400 dark:hover:bg-gray-700/50">{{ gp247_language_render('action.view_more') }}</a>
                @else
                    <p class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.notice.empty') }}</p>
                @endif
            </div>
        </div>

        <button type="button" x-on:click="$store.gp247.toggleTheme()"
            class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
            aria-label="{{ gp247_language_render('admin.toggle_dark') }}">
            <i class="fas" :class="dark ? 'fa-sun' : 'fa-moon'"></i>
        </button>

        <div class="relative" x-data="{ open: false }">
            <button type="button" x-on:click="open = ! open"
                class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                <img src="{{ $adminAvatar }}" alt="{{ $adminUser->name ?? '' }}" class="h-8 w-8 rounded-full object-cover">
                <span class="hidden sm:inline">{{ $adminUser->name ?? '' }}</span>
                <i class="fas fa-angle-down text-xs"></i>
            </button>

            <div x-show="open" x-on:click.outside="open = false" x-cloak
                class="absolute right-0 mt-2 w-60 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-col items-center gap-1 border-b border-gray-100 px-4 py-3 text-center dark:border-gray-700">
                    <img src="{{ $adminAvatar }}" alt="{{ $adminUser->name ?? '' }}" class="h-16 w-16 rounded-full object-cover">
                    <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $adminUser->name ?? '' }}</div>
                    @if ($adminUser)
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ gp247_language_render('admin.user.member_since') }} {{ $adminUser->created_at }}
                        </div>
                    @endif
                </div>
                <a href="{{ gp247_route_admin('admin.setting') }}"
                    class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                    <i class="fas fa-cog"></i>
                    {{ gp247_language_render('admin.user.setting') }}
                </a>
                <a href="{{ gp247_route_admin('admin.logout') }}"
                    class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                    <i class="fas fa-sign-out-alt"></i>
                    {{ gp247_language_render('admin.user.logout') }}
                </a>
            </div>
        </div>
    </div>
</header>
