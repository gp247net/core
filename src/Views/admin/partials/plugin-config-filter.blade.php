{{--
    Thanh chip lọc plugin theo nhóm configCode trên màn Quản lý plugin
    (US-PLG-config-code-filter, ADR plugin-manager_config-code-filter + Amendment 1).

    Hai chế độ:
      - client (mặc định; tab "Lưu trên máy", hoặc tab Online khi API không trả số đếm): lọc bằng Alpine
        trên các dòng đang có trong trang, multi-select (OR). Mỗi dòng bảng mang
        `data-config-group="<bucket>"` + `x-show="$store.pluginConfigFilter.visible('<bucket>')"`.
      - server (tab Online, mod 20261114T075507): mỗi chip là link bật/tắt nhóm trên query string,
        giữ các bộ lọc khác, về trang 1; số đếm là tổng toàn thư viện.

    LUÔN hiện đủ các nhãn; nhóm có 0 plugin vẫn hiện nhưng không bấm được (trừ khi đang được chọn).
    Chip đầu "Tất cả (N)" bỏ mọi lọc nhóm. Chỉ dùng class Tailwind đã có trong bundle core (gp247.md §3a).
    Chế độ client: class tĩnh chỉ là khung chip; màu theo trạng thái chỉ đặt ở `:class` (Alpine cộng thêm
    vào class tĩnh, không thay thế — đặt màu ở cả hai thì chip "Tất cả" giữ màu chọn khi đã chọn nhóm).

    Biến vào:
      - $groupCounts (array<string,int>): bucket => số plugin (bucket thiếu ⇒ 0).
      - $mode ('client'|'server'), mặc định 'client'.
      - $groups (string[]): nhóm đang chọn (chế độ server).
      - $filterQuery (array): các bộ lọc khác để giữ trên link (chế độ server).
      - $groupTotal (int|null): tổng cho chip "Tất cả"; null ⇒ tổng $groupCounts.

    @aidlc-unit plugin-manager
    @aidlc-story US-PLG-config-code-filter
    @aidlc-adr plugin-manager_config-code-filter
--}}
@php
    $pcfLabels = gp247_plugin_config_group_labels();
    $pcfCounts = $groupCounts ?? [];
    $pcfMode = ($mode ?? 'client') === 'server' ? 'server' : 'client';
    $pcfGroups = $groups ?? [];
    $pcfQuery = $filterQuery ?? [];
    unset($pcfQuery['group']);
    $pcfTotal = (int) ($groupTotal ?? array_sum($pcfCounts));
    $pcfBase = url()->current();
    $pcfChip = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1 text-xs font-medium transition';
    $pcfIdle = 'border-gray-300 bg-white text-gray-700 hover:bg-gray-100 hover:text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-600';
    $pcfOn = 'border-blue-600 bg-blue-600 text-white shadow-sm';
    $pcfOff = 'cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-500';
    $pcfBadgeIdle = 'bg-gray-100 text-gray-600 dark:bg-gray-600 dark:text-gray-200';
    $pcfBadgeOn = 'bg-blue-100 text-blue-700';
    $pcfBadge = 'inline-flex items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold leading-none';
@endphp
<div class="flex flex-wrap items-center gap-2" data-testid="plugin-config-filter">
    <span class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 dark:text-gray-400">
        <i class="fas fa-filter text-xs text-gray-400"></i>
        {{ gp247_language_render('admin.extension.filter_by_group') }}
    </span>

    {{-- "All": clears every group filter --}}
    @if ($pcfMode === 'server')
        <a href="{{ $pcfBase . ($pcfQuery ? '?' . http_build_query($pcfQuery) : '') }}"
            data-testid="plugin-config-filter-clear"
            class="{{ $pcfChip }} {{ $pcfGroups ? $pcfIdle : $pcfOn }}">
            <span>{{ gp247_language_render('admin.extension.filter_all') }}</span>
            <span data-testid="plugin-config-filter-count-all" class="{{ $pcfBadge }} {{ $pcfGroups ? $pcfBadgeIdle : $pcfBadgeOn }}">{{ $pcfTotal }}</span>
        </a>
    @else
        <button type="button"
            x-on:click="$store.pluginConfigFilter.clear()"
            data-testid="plugin-config-filter-clear"
            :class="$store.pluginConfigFilter.sel.length === 0 ? '{{ $pcfOn }}' : '{{ $pcfIdle }}'"
            class="{{ $pcfChip }}">
            <span>{{ gp247_language_render('admin.extension.filter_all') }}</span>
            <span data-testid="plugin-config-filter-count-all"
                :class="$store.pluginConfigFilter.sel.length === 0 ? '{{ $pcfBadgeOn }}' : '{{ $pcfBadgeIdle }}'"
                class="{{ $pcfBadge }}">{{ $pcfTotal }}</span>
        </button>
    @endif

    @foreach ($pcfLabels as $pcfCode => $pcfLabelKey)
        @php
            $pcfCount = (int) ($pcfCounts[$pcfCode] ?? 0);
            $pcfPicked = in_array($pcfCode, $pcfGroups, true);
        @endphp
        @if ($pcfCount === 0 && !$pcfPicked)
            {{-- Empty group: always shown, never clickable --}}
            <button type="button" disabled aria-disabled="true"
                data-testid="plugin-config-filter-chip-{{ $pcfCode }}"
                class="{{ $pcfChip }} {{ $pcfOff }}">
                <span>{{ gp247_language_render($pcfLabelKey) }}</span>
                <span data-testid="plugin-config-filter-count-{{ $pcfCode }}" class="{{ $pcfBadge }} {{ $pcfBadgeIdle }}">0</span>
            </button>
        @elseif ($pcfMode === 'server')
            @php
                $pcfNext = $pcfPicked ? array_values(array_diff($pcfGroups, [$pcfCode])) : array_merge($pcfGroups, [$pcfCode]);
                $pcfLinkQuery = $pcfQuery + ($pcfNext ? ['group' => $pcfNext] : []);
            @endphp
            <a href="{{ $pcfBase . ($pcfLinkQuery ? '?' . http_build_query($pcfLinkQuery) : '') }}"
                data-testid="plugin-config-filter-chip-{{ $pcfCode }}"
                aria-pressed="{{ $pcfPicked ? 'true' : 'false' }}"
                class="{{ $pcfChip }} {{ $pcfPicked ? $pcfOn : $pcfIdle }}">
                <span>{{ gp247_language_render($pcfLabelKey) }}</span>
                <span data-testid="plugin-config-filter-count-{{ $pcfCode }}" class="{{ $pcfBadge }} {{ $pcfPicked ? $pcfBadgeOn : $pcfBadgeIdle }}">{{ $pcfCount }}</span>
            </a>
        @else
            <button type="button"
                x-on:click="$store.pluginConfigFilter.toggle('{{ $pcfCode }}')"
                data-testid="plugin-config-filter-chip-{{ $pcfCode }}"
                :aria-pressed="$store.pluginConfigFilter.active('{{ $pcfCode }}') ? 'true' : 'false'"
                :class="$store.pluginConfigFilter.active('{{ $pcfCode }}') ? '{{ $pcfOn }}' : '{{ $pcfIdle }}'"
                class="{{ $pcfChip }}">
                <span>{{ gp247_language_render($pcfLabelKey) }}</span>
                <span data-testid="plugin-config-filter-count-{{ $pcfCode }}"
                    :class="$store.pluginConfigFilter.active('{{ $pcfCode }}') ? '{{ $pcfBadgeOn }}' : '{{ $pcfBadgeIdle }}'"
                    class="{{ $pcfBadge }}">{{ $pcfCount }}</span>
            </button>
        @endif
    @endforeach
</div>

@if ($pcfMode === 'client')
<script>
    (function () {
        // WHY: register on BOTH paths. On the first full page load Alpine is not up yet
        // (wait for alpine:init); when this screen arrives via wire:navigate (SPA), Alpine
        // is ALREADY running and alpine:init never fires again — so register immediately.
        // Without this the store is missing on a navigated-in visit, every row's
        // x-show errors and the table renders empty until a hard reload.
        function registerPluginConfigFilter() {
            if (!window.Alpine || Alpine.store('pluginConfigFilter')) {
                return;
            }
            Alpine.store('pluginConfigFilter', {
                sel: [],
                // Bucket of every filterable row on the page, to tell when a filter hides them all.
                buckets: [],
                toggle(code) {
                    const i = this.sel.indexOf(code);
                    if (i > -1) {
                        this.sel.splice(i, 1);
                    } else {
                        this.sel.push(code);
                    }
                },
                active(code) {
                    return this.sel.includes(code);
                },
                // No selection => everything visible; otherwise OR across selected buckets.
                visible(code) {
                    return this.sel.length === 0 || this.sel.includes(code);
                },
                // True when a filter is on and none of the rows matches it.
                empty() {
                    return this.sel.length > 0 && !this.buckets.some((b) => this.sel.includes(b));
                },
                clear() {
                    this.sel = [];
                },
            });
        }

        if (window.Alpine) {
            registerPluginConfigFilter();
        } else {
            document.addEventListener('alpine:init', registerPluginConfigFilter);
        }
    })();
</script>
@endif
