{{--
    GP247 admin page header + breadcrumb (ADR-002).

    Shows the page title (h1) and an accessible Home › … › Current trail with
    chevron separators, truncation for long labels, and a structured-data schema
    (BreadcrumbList) for SEO. The optional mid-level crumb mirrors the brownfield
    $breadcrumb array shape so existing screens can pass it unchanged.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-LW-001
    @aidlc-adr ADR-002

    Variables:
      - $title (string): current page title.
      - $breadcrumb (array|null): ['name' => ..., 'url' => ...] mid-level crumb.
--}}
@php
    $crumbs = [];
    $crumbs[] = [
        'label' => gp247_language_render('admin.home'),
        'url'   => gp247_route_admin('admin.home'),
        'icon'  => 'fas fa-home',
    ];
    if (!empty($breadcrumb['name'])) {
        $crumbs[] = [
            'label' => $breadcrumb['name'],
            'url'   => $breadcrumb['url'] ?? null,
            'icon'  => null,
        ];
    }
    $crumbs[] = ['label' => $title, 'url' => null, 'icon' => null];
@endphp

<div class="mb-6 border-b border-gray-200 pb-4 dark:border-gray-700">
    <div class="flex flex-col gap-1.5 sm:flex-row sm:items-end sm:justify-between">

        {{-- Page title --}}
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-800 dark:text-gray-100">
                {{ $title }}
            </h1>
        </div>

        {{-- Breadcrumb trail --}}
        <nav aria-label="{{ gp247_language_render('admin.breadcrumb') }}">
            <ol class="flex flex-wrap items-center gap-1 text-sm"
                itemscope itemtype="https://schema.org/BreadcrumbList">

                @foreach ($crumbs as $i => $crumb)
                    @php $isLast = $i === count($crumbs) - 1; @endphp

                    @if ($i > 0)
                        <li class="text-gray-400 dark:text-gray-500" aria-hidden="true">
                            <i class="fas fa-chevron-right text-[10px]"></i>
                        </li>
                    @endif

                    <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                        <meta itemprop="position" content="{{ $i + 1 }}">

                        @if (!$isLast && $crumb['url'])
                            <a href="{{ $crumb['url'] }}"
                               wire:navigate
                               itemprop="item"
                               class="flex max-w-[160px] items-center gap-1.5 truncate rounded px-1.5 py-0.5 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700/50 dark:hover:text-gray-200">
                                @if ($crumb['icon'])<i class="{{ $crumb['icon'] }} text-xs"></i>@endif
                                <span itemprop="name">{{ $crumb['label'] }}</span>
                            </a>
                        @else
                            <span class="flex max-w-[200px] items-center gap-1.5 truncate rounded px-1.5 py-0.5
                                         {{ $isLast
                                             ? 'font-medium text-blue-600 dark:text-blue-400'
                                             : 'text-gray-500 dark:text-gray-400' }}"
                                  itemprop="name">
                                @if ($crumb['icon'])<i class="{{ $crumb['icon'] }} text-xs"></i>@endif
                                {{ $crumb['label'] }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    </div>
</div>
