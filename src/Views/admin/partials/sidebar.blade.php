{{--
    GP247 admin sidebar (ADR-002).

    Renders the brownfield admin menu tree (`AdminMenu::getListVisible()`, already
    permission-filtered) with TailAdmin styling. Slides in/out on mobile via the
    Alpine `gp247` store; static on large screens. Collapsible groups use a local
    Alpine `open` flag. Active state reuses `AdminMenu::checkUrlIsChild`.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-LW-001
    @aidlc-adr ADR-002

    Variables:
      - $gp247Menus (array): menu tree keyed by parent id; index 0 = top-level groups.
--}}
@php
    $linkBase = 'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition';
    $linkIdle = 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white';
    $linkActive = 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-200';
@endphp

<aside
    :class="{
        'translate-x-0': sidebarOpen,
        '-translate-x-full': ! sidebarOpen,
        'lg:w-20 gp247-rail': sidebarCollapsed,
    }"
    class="fixed inset-y-0 left-0 z-30 flex w-64 transform flex-col border-r border-gray-200 bg-white transition-all duration-200 dark:border-gray-700 dark:bg-gray-800 lg:static lg:translate-x-0"
>
    <a href="{{ gp247_route_admin('admin.home') }}" wire:navigate
        class="gp247-brand flex h-16 shrink-0 items-center justify-center border-b border-gray-200 px-5 dark:border-gray-700">
        <img src="{{ gp247_file(gp247_store_info('logo')) }}"
            alt="{{ gp247_config_admin('ADMIN_NAME') }}" class="max-h-9 w-auto max-w-full">
    </a>

    <nav class="flex-1 space-y-4 overflow-y-auto px-3 py-4">
        @if (! empty($gp247Menus[0]))
            @foreach ($gp247Menus[0] as $level0)
                @php $children = $gp247Menus[$level0->id] ?? []; @endphp
                @if (! empty($children))
                    {{-- WHY: a top border + extra padding between groups (skipped on the
                         first group) gives each section a visible boundary — without it,
                         all groups run together as one continuous list (see modification
                         requesting distinguishable menu blocks). --}}
                    <div class="{{ $loop->first ? '' : 'border-t border-gray-100 pt-4 dark:border-gray-700' }}">
                        <p class="gp247-section-label px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                            {!! gp247_language_render($level0->title) !!}
                        </p>
                        <ul class="space-y-1">
                            @foreach ($children as $level1)
                                @php $grandChildren = empty($level1->uri) ? ($gp247Menus[$level1->id] ?? []) : []; @endphp
                                @if (! empty($level1->uri))
                                    @php $active = \GP247\Core\Models\AdminMenu::checkUrlIsChild(url()->current(), gp247_url_render($level1->uri)); @endphp
                                    <li>
                                        <a href="{{ gp247_url_render($level1->uri) }}" wire:navigate
                                            class="{{ $linkBase }} {{ $active ? $linkActive : $linkIdle }}">
                                            <i class="{{ $level1->icon }} w-5 text-center"></i>
                                            <span class="gp247-nav-label">{!! gp247_language_render($level1->title) !!}</span>
                                        </a>
                                    </li>
                                @elseif (! empty($grandChildren))
                                    @php
                                        // WHY: auto-expand the group so the active child link is visible on page load.
                                        $groupActive = collect($grandChildren)->contains(
                                            fn ($c) => !empty($c->uri) && \GP247\Core\Models\AdminMenu::checkUrlIsChild(url()->current(), gp247_url_render($c->uri))
                                        );
                                    @endphp
                                    <li x-data="{ open: {{ $groupActive ? 'true' : 'false' }} }">
                                        <button type="button" x-on:click="open = ! open"
                                            class="{{ $linkBase }} {{ $groupActive ? $linkActive : $linkIdle }} w-full justify-between">
                                            <span class="flex items-center gap-3">
                                                <i class="{{ $level1->icon }} w-5 text-center"></i>
                                                <span class="gp247-nav-label">{!! gp247_language_render($level1->title) !!}</span>
                                            </span>
                                            <i class="gp247-nav-caret fas fa-angle-left transition" :class="open && '-rotate-90'"></i>
                                        </button>
                                        <ul x-show="open" x-collapse class="gp247-submenu mt-1 space-y-1 pl-7">
                                            @foreach ($grandChildren as $level2)
                                                @if (! empty($level2->uri))
                                                    @php $active = \GP247\Core\Models\AdminMenu::checkUrlIsChild(url()->current(), gp247_url_render($level2->uri)); @endphp
                                                    <li>
                                                        <a href="{{ gp247_url_render($level2->uri) }}" wire:navigate
                                                            class="{{ $linkBase }} {{ $active ? $linkActive : $linkIdle }}">
                                                            <i class="{{ $level2->icon }} w-4 text-center text-xs"></i>
                                                            <span class="gp247-nav-label">{!! gp247_language_render($level2->title) !!}</span>
                                                        </a>
                                                    </li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        @endif
    </nav>
</aside>
