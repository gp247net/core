@extends('gp247-admin::layouts.plain')

@section('main')
<div class="space-y-4">

    {{-- API error banner --}}
    @if ($errorCode)
        <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/40 dark:bg-amber-900/20">
            <i class="fas fa-triangle-exclamation mt-0.5 text-amber-500"></i>
            <div class="flex-1 text-sm">
                <p class="font-semibold text-amber-800 dark:text-amber-300">
                    {{ gp247_language_render('admin.extension.api_error') }}
                </p>
                <p class="mt-0.5 text-amber-700 dark:text-amber-400">
                    <strong>{{ gp247_language_render('admin.extension.api_error_code') }}:</strong> {{ $errorCode }}
                    &nbsp;|&nbsp;
                    <strong>{{ gp247_language_render('admin.extension.api_error_content') }}:</strong> {{ $errorMessage }}
                </p>
                <p class="mt-1 text-amber-600 dark:text-amber-500">
                    {{ gp247_language_render('admin.extension.api_error_register_hint') }}
                    <a href="#" onclick="registerLicense(event)"
                       class="font-medium underline hover:no-underline">
                        {{ gp247_language_render('admin.extension.api_error_register_hint_link') }}
                    </a>
                </p>
            </div>
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">

        {{-- Tab navigation --}}
        <div class="border-b border-gray-200 px-1 dark:border-gray-700">
            <nav class="-mb-px flex flex-wrap">
                <a href="{{ $urlAction['local'] }}"
                   class="inline-flex items-center gap-1.5 border-b-2 border-transparent px-5 py-3 text-sm font-medium text-gray-500 transition hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:text-gray-200">
                    <i class="fas fa-puzzle-piece text-xs"></i>
                    {{ gp247_language_render('admin.extension.local') }}
                </a>
                <span class="inline-flex items-center gap-1.5 border-b-2 border-blue-500 px-5 py-3 text-sm font-medium text-blue-600 dark:border-blue-400 dark:text-blue-400">
                    <i class="fas fa-globe text-xs"></i>
                    {{ gp247_language_render('admin.extension.online') }}
                </span>
                <a href="{{ $urlAction['urlImport'] }}"
                   class="inline-flex items-center gap-1.5 border-b-2 border-transparent px-5 py-3 text-sm font-medium text-gray-500 transition hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:text-gray-200">
                    <i class="fas fa-upload text-xs"></i>
                    {{ gp247_language_render('admin.extension.import') }}
                </a>
            </nav>
        </div>

        {{-- Filter toolbar --}}
        <div class="border-b border-gray-100 bg-gray-50 px-4 py-3 dark:border-gray-700/50 dark:bg-gray-700/30">
            <form method="GET" action="{{ url()->current() }}" class="flex flex-wrap items-center gap-2">
                <select name="is_free"
                    class="h-9 w-40 rounded-lg border border-gray-300 bg-white px-2.5 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                    <option value="">{{ gp247_language_render('admin.extension.all_items') }}</option>
                    <option value="1" {{ ($is_free == 1) ? 'selected' : '' }}>{{ gp247_language_render('admin.extension.only_free') }}</option>
                </select>

                <select name="type_sort"
                    class="h-9 w-44 rounded-lg border border-gray-300 bg-white px-2.5 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">
                    <option value="">{{ gp247_language_render('admin.extension.sort_default') }}</option>
                    <option value="download" {{ ($type_sort == 'download') ? 'selected' : '' }}>{{ gp247_language_render('admin.extension.sort_download') }}</option>
                    <option value="rating"   {{ ($type_sort == 'rating')   ? 'selected' : '' }}>{{ gp247_language_render('admin.extension.sort_rating') }}</option>
                    <option value="sort_price_asc"  {{ ($type_sort == 'sort_price_asc')  ? 'selected' : '' }}>{{ gp247_language_render('admin.extension.sort_price_asc') }}</option>
                    <option value="sort_price_desc" {{ ($type_sort == 'sort_price_desc') ? 'selected' : '' }}>{{ gp247_language_render('admin.extension.sort_price_desc') }}</option>
                </select>

                <div class="relative min-w-0 flex-1">
                    <i class="fas fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
                    <input type="text" name="keyword" value="{{ $keyword ?? '' }}"
                        placeholder="{{ gp247_language_render('admin.extension.enter_search_keyword') }}"
                        class="h-9 w-full rounded-lg border border-gray-300 bg-white pl-8 pr-3 text-sm text-gray-700 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:placeholder-gray-400">
                </div>

                <button type="submit"
                    class="inline-flex h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg bg-blue-600 px-4 text-sm font-medium text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1">
                    <i class="fas fa-filter text-xs"></i>
                    {{ gp247_language_render('admin.extension.sort') }}
                </button>
            </form>
        </div>

        {{-- Table --}}
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="w-16 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.extension.image') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.extension.name') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.extension.version') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.extension.auth') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.extension.compatible') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.extension.price') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.extension.rated') }}</th>
                        <th class="w-16 px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-download"></i></th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.extension.action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                    @if (!$arrExtensions)
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center">
                                <i class="fas fa-cloud-arrow-down mb-3 text-3xl text-gray-300 dark:text-gray-600"></i>
                                <p class="text-sm text-gray-400 dark:text-gray-500">
                                    {{ gp247_language_render('admin.extension.empty') }}
                                </p>
                            </td>
                        </tr>
                    @else
                        @foreach ($arrExtensions as $extension)
                            @php
                                $gp247Versions = explode(',', $extension['gp247_version']);
                                $isLocal = array_key_exists($extension['key'], $arrExtensionsLocal);
                                $isFree  = $extension['is_free'] || $extension['price_final'] == 0;
                                $hasDiscount = !$isFree && $extension['price_final'] != $extension['price'];
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">

                                {{-- Image --}}
                                <td class="px-4 py-3">
                                    <button type="button" onclick="previewImage('{{ $extension['image_demo'] ?? '' }}', '{{ $extension['name'] }}')"
                                        class="group relative block">
                                        {!! gp247_image_render($extension['image'], '44px', 'rounded-lg border border-gray-200 dark:border-gray-600 group-hover:opacity-80 transition', $extension['name']) !!}
                                        <span class="absolute inset-0 flex items-center justify-center opacity-0 transition group-hover:opacity-100">
                                            <i class="fas fa-magnifying-glass-plus text-white drop-shadow"></i>
                                        </span>
                                    </button>
                                </td>

                                {{-- Name + desc --}}
                                <td class="px-4 py-3">
                                    <p class="font-medium text-gray-800 dark:text-gray-100">{{ $extension['name'] }}</p>
                                    <p class="mt-0.5 line-clamp-1 max-w-xs text-xs text-gray-400">
                                        {{ strip_tags($extension['description'] ?? '') }}
                                    </p>
                                    <code class="mt-1 inline-block rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        {{ $extension['key'] }}
                                    </code>
                                </td>

                                {{-- Version --}}
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                    {{ $extension['version'] ?? '' }}
                                </td>

                                {{-- Author --}}
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                    {{ $extension['username'] ?? '' }}
                                </td>

                                {{-- Compatible --}}
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($gp247Versions as $v)
                                            <span class="inline-block rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                                {{ trim($v) }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>

                                {{-- Price --}}
                                <td class="px-4 py-3">
                                    @if ($isFree)
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-300">
                                            {{ gp247_language_render('admin.extension.free') }}
                                        </span>
                                    @else
                                        @if ($hasDiscount)
                                            <span class="block text-xs text-gray-400 line-through">${{ number_format($extension['price']) }}</span>
                                        @endif
                                        <span class="font-semibold text-gray-800 dark:text-gray-100">${{ number_format($extension['price_final']) }}</span>
                                    @endif
                                </td>

                                {{-- Rating --}}
                                <td class="px-4 py-3">
                                    @php
                                        $cal_vote  = (float) number_format($extension['rated'], 1);
                                        $full      = (int) $cal_vote;
                                        $half      = ($cal_vote != round($cal_vote));
                                        $empty     = 5 - $full - ($half ? 1 : 0);
                                    @endphp
                                    <div class="flex items-center gap-0.5 text-amber-400" title="{{ $cal_vote }}/5">
                                        @for ($i = 0; $i < $full; $i++)<i class="fas fa-star text-xs"></i>@endfor
                                        @if ($half)<i class="fas fa-star-half-stroke text-xs"></i>@endif
                                        @for ($i = 0; $i < $empty; $i++)<i class="far fa-star text-xs"></i>@endfor
                                    </div>
                                    <p class="mt-0.5 text-xs text-gray-400">{{ $extension['points'] }}/{{ $extension['times'] }}</p>
                                </td>

                                {{-- Downloads --}}
                                <td class="px-4 py-3 text-center text-gray-500 dark:text-gray-400">
                                    {{ $extension['download'] ?? '—' }}
                                </td>

                                {{-- Actions --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1.5">
                                        @if ($isLocal)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-300">
                                                <i class="fas fa-check"></i>
                                                {{ gp247_language_render('admin.extension.located') }}
                                            </span>
                                        @elseif ($isFree)
                                            <button type="button"
                                                title="{{ gp247_language_render('admin.extension.install') }}"
                                                onclick="installOnline('{{ $extension['key'] }}', '{{ $extension['file'] }}')"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-green-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-1">
                                                <i class="fas fa-download"></i>
                                                {{ gp247_language_render('admin.extension.install') }}
                                            </button>
                                        @endif
                                        <a href="{{ $extension['link'] }}" target="_blank" rel="noopener"
                                            title="{{ gp247_language_render('admin.extension.link') }}"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                                            <i class="fas fa-arrow-up-right-from-square"></i>
                                            {{ gp247_language_render('admin.extension.link') }}
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if (!empty($htmlPaging) || !empty($resultItems))
            <div class="flex items-center justify-between border-t border-gray-100 px-4 py-3 dark:border-gray-700/50">
                <div class="text-sm text-gray-500 dark:text-gray-400">{!! $resultItems ?? '' !!}</div>
                <div>{!! $htmlPaging ?? '' !!}</div>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const _installUrl = @js($urlAction['install']);
    const _csrf = @js(csrf_token());

    function notify(type, msg) {
        window.dispatchEvent(new CustomEvent('notify', { detail: { type, message: msg } }));
    }
    function loading(show) {
        const el = document.getElementById('gp247-page-loading');
        if (el) el.style.display = show ? 'flex' : 'none';
    }

    window.installOnline = async function (key, path) {
        loading(true);
        const body = new URLSearchParams({ _token: _csrf, key, path });
        try {
            const res  = await fetch(_installUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            const data = await res.json();
            if (parseInt(data.error) === 0) {
                notify('success', data.msg);
                location.reload();
            } else {
                notify('error', data.msg);
                loading(false);
            }
        } catch (e) {
            notify('error', e.message);
            loading(false);
        }
    };

    window.previewImage = function (url, name) {
        if (!url) return;
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 p-4';
        overlay.innerHTML = `
            <div class="relative max-w-3xl w-full">
                <button onclick="this.closest('.fixed').remove()"
                    class="absolute -right-3 -top-3 flex h-8 w-8 items-center justify-center rounded-full bg-white text-gray-700 shadow-lg hover:bg-gray-100">&times;</button>
                <img src="${url}" alt="${name}" class="w-full rounded-xl shadow-2xl">
                <p class="mt-2 text-center text-sm text-white/80">${name}</p>
            </div>`;
        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);
    };

    window.registerLicense = async function (e) {
        e.preventDefault();
        loading(true);
        try {
            const res  = await fetch(@js(gp247_route_admin('admin_plugin_online.register-license')), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ _token: _csrf }),
            });
            const data = await res.json();
            if (data.status === 'success') {
                notify('success', data.message);
                location.reload();
            } else {
                notify('error', data.message);
                loading(false);
            }
        } catch (e) {
            notify('error', e.message);
            loading(false);
        }
    };
})();
</script>
@endpush
