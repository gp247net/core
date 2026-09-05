{{--
    Thanh chip lọc plugin theo nhóm configCode trên màn Quản lý plugin
    (US-PLG-config-code-filter, ADR plugin-manager_config-code-filter).

    Lọc CLIENT-SIDE (Alpine), multi-select (chọn nhiều = hiện plugin thuộc BẤT KỲ nhóm đã
    chọn — OR), mỗi chip hiển thị tổng số plugin của nhóm. KHÔNG kiểm active — phân loại mọi
    plugin đang liệt kê trong tab hiện tại. LUÔN hiện đủ 6 nhãn (kể cả nhóm có 0 plugin).

    Dùng chung cho cả tab "Lưu trên máy" và "Online"; mỗi dòng plugin của bảng phải mang
    `data-config-group="<bucket>"` và `x-show="$store.pluginConfigFilter.visible('<bucket>')"`
    (screen tự gắn). Store toàn cục — chỉ một màn được nạp mỗi lần.

    Biến vào:
      - $groupCounts (array<string,int>): bucketCode => số plugin (screen đếm sẵn, đã bỏ bucket rỗng).

    @aidlc-unit plugin-manager
    @aidlc-story US-PLG-config-code-filter
    @aidlc-adr plugin-manager_config-code-filter
--}}
@php $pcfLabels = gp247_plugin_config_group_labels(); $pcfCounts = $groupCounts ?? []; @endphp
{{-- LUÔN render đủ 6 nhãn (kể cả count 0) — không ẩn nhóm rỗng. Chỉ dùng class Tailwind
     đã có sẵn trong bundle core (gp247.md §3a) để giữ hiệu lực style. --}}
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 dark:border-gray-600 dark:bg-gray-700" data-testid="plugin-config-filter">
        <span class="inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            <i class="fas fa-filter text-xs text-gray-400"></i>
            {{ gp247_language_render('admin.extension.filter_by_group') }}
        </span>
        @foreach ($pcfLabels as $pcfCode => $pcfLabelKey)
            <button type="button"
                x-on:click="$store.pluginConfigFilter.toggle('{{ $pcfCode }}')"
                data-testid="plugin-config-filter-chip-{{ $pcfCode }}"
                :class="$store.pluginConfigFilter.active('{{ $pcfCode }}')
                    ? 'border-blue-600 bg-blue-600 text-white shadow-sm'
                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-100 hover:text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-600'"
                class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium transition">
                <span>{{ gp247_language_render($pcfLabelKey) }}</span>
                <span data-testid="plugin-config-filter-count-{{ $pcfCode }}"
                    :class="$store.pluginConfigFilter.active('{{ $pcfCode }}')
                        ? 'bg-blue-100 text-blue-700'
                        : 'bg-gray-100 text-gray-600 dark:bg-gray-600 dark:text-gray-200'"
                    class="inline-flex items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold leading-none">{{ (int) ($pcfCounts[$pcfCode] ?? 0) }}</span>
            </button>
        @endforeach
        <button type="button"
            x-show="$store.pluginConfigFilter.sel.length > 0"
            x-on:click="$store.pluginConfigFilter.clear()"
            data-testid="plugin-config-filter-clear"
            class="ml-1 inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">
            <i class="fas fa-xmark text-xs"></i>
            <span>{{ gp247_language_render('admin.extension.filter_clear') }}</span>
        </button>
    </div>

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
