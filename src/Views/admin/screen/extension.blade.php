@extends('gp247-admin::layouts.plain')

@section('main')
<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">

    {{-- Tab navigation --}}
    <div class="border-b border-gray-200 px-1 dark:border-gray-700">
        <nav class="-mb-px flex flex-wrap" aria-label="{{ gp247_language_render('admin.extension.management', ['extension' => $groupType]) }}">
            <span class="inline-flex items-center gap-1.5 border-b-2 border-blue-500 px-5 py-3 text-sm font-medium text-blue-600 dark:border-blue-400 dark:text-blue-400">
                <i class="fas fa-puzzle-piece text-xs"></i>
                {{ gp247_language_render('admin.extension.local') }}
            </span>
            @if ($configExtension)
            <a href="{{ $listUrlAction['urlOnline'] }}"
               class="inline-flex items-center gap-1.5 border-b-2 border-transparent px-5 py-3 text-sm font-medium text-gray-500 transition hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:text-gray-200">
                <i class="fas fa-globe text-xs"></i>
                {{ gp247_language_render('admin.extension.online') }}
            </a>
            @endif
            <a href="{{ $listUrlAction['urlImport'] }}"
               class="inline-flex items-center gap-1.5 border-b-2 border-transparent px-5 py-3 text-sm font-medium text-gray-500 transition hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:text-gray-200">
                <i class="fas fa-upload text-xs"></i>
                {{ gp247_language_render('admin.extension.import') }}
            </a>
        </nav>
    </div>

    {{-- Per-store selector (multi-store only): root admin picks a store to configure
         each storeScope=store plugin's on/off for just that store. --}}
    @if (!empty($perStoreEnable))
    <div class="border-b border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/40">
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                <i class="fas fa-store text-gray-400 dark:text-gray-500"></i>
                {{ gp247_language_render('admin.extension.per_store_config') }}
            </span>
            <form method="GET" action="{{ url()->current() }}">
                <select name="store_id" data-testid="extension-store-select" onchange="this.form.submit()"
                    class="min-w-[220px] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">— {{ gp247_language_render('admin.extension.all_stores_option') }} —</option>
                    @foreach ($storeList as $sid => $stitle)
                        <option value="{{ $sid }}" @selected((string) $selectedStoreId === (string) $sid)>{{ $stitle }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        @if ($selectedStoreId !== '')
            <p class="mt-2 flex items-start gap-2 rounded-lg border border-blue-100 bg-blue-50/70 px-3 py-2 text-xs leading-relaxed text-blue-700 dark:border-blue-900/40 dark:bg-blue-900/15 dark:text-blue-300">
                <i class="fas fa-info-circle mt-0.5 shrink-0"></i>
                <span>{{ gp247_language_render('admin.extension.per_store_hint') }}</span>
            </p>
        @endif
    </div>
    @endif

    @php
        // Pre-pass: classify every LOCAL plugin by configCode for the multi-select filter
        // chip bar (US-PLG-config-code-filter). NO active check — every listed plugin is
        // counted and taggable, regardless of enabled state. Plugins tab only.
        $pluginConfigBucket = [];
        $pluginConfigCounts = [];
        if ($groupType === 'Plugins') {
            foreach ($extensions as $pcfKey => $pcfClassName) {
                try {
                    $pcfCode = (new ($pcfClassName . '\\AppConfig'))->configCode ?? '';
                } catch (\Throwable $pcfE) {
                    $pcfCode = '';
                }
                $pcfBucket = gp247_plugin_config_group($pcfCode);
                $pluginConfigBucket[$pcfKey] = $pcfBucket;
                $pluginConfigCounts[$pcfBucket] = ($pluginConfigCounts[$pcfBucket] ?? 0) + 1;
            }
        }
    @endphp

    {{-- Alpine scope for the configCode filter (chips + rows share $store.pluginConfigFilter) --}}
    <div @if ($groupType === 'Plugins') x-data @endif>
        @if ($groupType === 'Plugins')
            @include('gp247-admin::partials.plugin-config-filter', ['groupCounts' => $pluginConfigCounts])
        @endif

    {{-- Table --}}
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr>
                    <th class="w-16 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ gp247_language_render('admin.extension.image') }}
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ gp247_language_render('admin.extension.name') }}
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ gp247_language_render('admin.extension.key') }}
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ gp247_language_render('admin.extension.scope') }}
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ gp247_language_render('admin.extension.version') }}
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ gp247_language_render('admin.extension.auth') }}
                    </th>
                    <th class="w-16 px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ gp247_language_render('admin.extension.sort') }}
                    </th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ gp247_language_render('admin.extension.action') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                @if (!$extensions)
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center">
                            <i class="fas fa-box-open mb-3 text-3xl text-gray-300 dark:text-gray-600"></i>
                            <p class="text-sm text-gray-400 dark:text-gray-500">
                                {{ gp247_language_render('admin.extension.empty') }}
                            </p>
                        </td>
                    </tr>
                @else
                    @foreach ($extensions as $keyExtension => $extensionClassName)
                        @php
                        try {
                            $classConfig  = $extensionClassName . '\\AppConfig';
                            $pluginClass  = new $classConfig;
                            $isInstalled  = array_key_exists($keyExtension, $extensionsInstalled->toArray());
                            $isEnabled    = $isInstalled && $extensionsInstalled[$keyExtension]['value'] == 1;
                            $isProtected  = in_array($keyExtension, $extensionProtected);
                            $isDefaultTpl = defined('GP247_TEMPLATE_FRONT_DEFAULT') && $keyExtension === GP247_TEMPLATE_FRONT_DEFAULT;
                            $isTplInUse   = $groupType === 'Templates'
                                && (new \GP247\Core\Models\AdminStore)->where('template', $keyExtension)->count() > 0;
                            // Per-store enable context (only when a store is picked on a
                            // multi-store site): the plugin's declared scope + its effective
                            // on/off for the selected store (own row → inherit GLOBAL).
                            $pluginScope  = ($groupType === 'Plugins') ? gp247_extension_scope('Plugins', $keyExtension) : 'global';
                            $perStoreRow  = (!empty($perStoreEnable) && $selectedStoreId !== '' && $isInstalled && $pluginScope === 'store');
                            $storeEnabled = $perStoreRow ? gp247_plugin_store_enabled($keyExtension, $selectedStoreId) : null;
                            // Whether this store has its OWN override row (vs inheriting the shared/GLOBAL state).
                            $storeOverride = $perStoreRow && \GP247\Core\Models\AdminConfig::where('group', 'Plugins')
                                ->where('key', $keyExtension)->where('store_id', $selectedStoreId)->exists();
                            $hasError     = false;
                        } catch (\Throwable $e) {
                            $hasError = true;
                            $errorMsg = json_encode($extensionClassName) . ': ' . $e->getMessage()
                                . "\n*File* `" . $e->getFile() . "`, *Line:* " . $e->getLine()
                                . ', *Code:* ' . $e->getCode() . PHP_EOL . 'URL= ' . url()->current();
                            gp247_report($errorMsg);
                        }
                        @endphp

                        @if ($hasError)
                            <tr class="bg-red-50 dark:bg-red-900/10">
                                <td colspan="8" class="px-4 py-3 text-xs text-red-600 dark:text-red-400">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>{{ $errorMsg ?? '' }}
                                </td>
                            </tr>
                        @else
                        @php $pcfRowBucket = $pluginConfigBucket[$keyExtension] ?? 'Other'; @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50"
                            @if ($groupType === 'Plugins')
                                data-config-group="{{ $pcfRowBucket }}"
                                {{-- Belt: if the store is momentarily missing, show the row (never hide data). --}}
                                x-show="$store.pluginConfigFilter?.visible('{{ $pcfRowBucket }}') ?? true"
                            @endif>
                            {{-- Thumbnail --}}
                            <td class="px-4 py-3">
                                {!! gp247_image_render(
                                    'GP247/' . $pluginClass->image,
                                    '44px',
                                    'rounded-lg border border-gray-200 dark:border-gray-600',
                                    $pluginClass->title
                                ) !!}
                            </td>

                            {{-- Name --}}
                            <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-100">
                                {{ $pluginClass->title }}
                            </td>

                            {{-- Key --}}
                            <td class="px-4 py-3">
                                <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                    {{ $keyExtension }}
                                </code>
                            </td>

                            {{-- Scope (store / global) --}}
                            <td class="px-4 py-3">
                                @if ($groupType === 'Plugins')
                                    <span class="gp247-scope gp247-scope-{{ $pluginScope }}">{{ $pluginScope }}</span>
                                @else
                                    <span class="gp247-ext-muted">—</span>
                                @endif
                            </td>

                            {{-- Version --}}
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                {{ $pluginClass->version ?? '' }}
                                @if (!empty($arrUpdates[$groupType.'|'.$keyExtension]))
                                    @if ($configExtension)
                                        <a href="{{ $listUrlAction['urlOnline'] }}"
                                            title="{{ gp247_language_render('admin.extension.update') }}"
                                            class="ml-1 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-900 dark:text-amber-300">
                                            <i class="fas fa-arrow-circle-up"></i>
                                            {{ gp247_language_render('admin.extension.update_available', ['version' => $arrUpdates[$groupType.'|'.$keyExtension]['version']]) }}
                                        </a>
                                    @else
                                        <span class="ml-1 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-900 dark:text-amber-300">
                                            <i class="fas fa-arrow-circle-up"></i>
                                            {{ gp247_language_render('admin.extension.update_available', ['version' => $arrUpdates[$groupType.'|'.$keyExtension]['version']]) }}
                                        </span>
                                    @endif
                                @endif
                            </td>

                            {{-- Author --}}
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                @if ($pluginClass->link ?? '')
                                    <a href="{{ $pluginClass->link }}" target="_blank" rel="noopener"
                                       class="text-blue-600 hover:underline dark:text-blue-400">
                                        {{ $pluginClass->auth ?? '' }}
                                    </a>
                                @else
                                    {{ $pluginClass->auth ?? '' }}
                                @endif
                            </td>

                            {{-- Sort --}}
                            <td class="px-4 py-3 text-center text-gray-500 dark:text-gray-400">
                                {{ $extensionsInstalled[$keyExtension]['sort'] ?? '—' }}
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if (!empty($perStoreEnable) && $selectedStoreId !== '' && $groupType === 'Plugins')
                                        {{-- Per-store mode: a single on/off switch writes only this store's
                                             override row (US-PLG-per-store-plugin-enable-list). Install/config/
                                             remove stay system-wide, done in the "All stores" view. --}}
                                        @if (!$isInstalled)
                                            <span class="gp247-ext-muted" title="{{ gp247_language_render('admin.extension.install') }}">—</span>
                                        @elseif ($pluginScope !== 'store')
                                            {{-- global scope: one shared value — the Scope column already says so,
                                                 so no per-store status text here; only the config shortcut (if any). --}}
                                            @if ($isEnabled && ($pluginClass->clickApp() ?? false))
                                                <a href="{{ url()->current() }}?action=config&key={{ $keyExtension }}"
                                                    title="{{ gp247_language_render('admin.extension.config') }}"
                                                    class="action-btn bg-blue-600 hover:bg-blue-700 focus:ring-blue-500">
                                                    <i class="fas fa-cog text-xs"></i>
                                                </a>
                                            @endif
                                        @else
                                            <span class="gp247-ext-status {{ $storeEnabled ? 'is-on' : '' }}">
                                                @if ($storeOverride)
                                                    {{ $storeEnabled ? gp247_language_render('admin.extension.own_on') : gp247_language_render('admin.extension.own_off') }}
                                                @else
                                                    {{ $storeEnabled ? gp247_language_render('admin.extension.inherit_on') : gp247_language_render('admin.extension.inherit_off') }}
                                                @endif
                                            </span>
                                            <label class="gp247-switch" title="{{ $storeEnabled ? gp247_language_render('admin.extension.disable') : gp247_language_render('admin.extension.enable') }}">
                                                <input type="checkbox" data-testid="extension-store-toggle" {{ $storeEnabled ? 'checked' : '' }}
                                                    onchange="extensionAction(this.checked ? 'enable' : 'disable', '{{ $keyExtension }}', '{{ $selectedStoreId }}')">
                                                <span class="gp247-switch-slider"></span>
                                            </label>
                                            @if ($isEnabled && ($pluginClass->clickApp() ?? false))
                                                <a href="{{ url()->current() }}?action=config&key={{ $keyExtension }}"
                                                    title="{{ gp247_language_render('admin.extension.config') }}"
                                                    class="action-btn bg-blue-600 hover:bg-blue-700 focus:ring-blue-500">
                                                    <i class="fas fa-cog text-xs"></i>
                                                </a>
                                            @endif
                                        @endif
                                    @else
                                    @if ($isDefaultTpl)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                            <i class="fas fa-shield-alt"></i>
                                            Default
                                        </span>
                                    @elseif ($isTplInUse)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-300">
                                            <i class="fas fa-check"></i>
                                            {{ gp247_language_render('admin.extension.used') }}
                                        </span>
                                    @else
                                        {{-- Install --}}
                                        @if (!$isInstalled)
                                            <button type="button"
                                                title="{{ gp247_language_render('admin.extension.install') }}"
                                                onclick="extensionAction('install', '{{ $keyExtension }}')"
                                                class="action-btn bg-green-600 hover:bg-green-700 focus:ring-green-500">
                                                <i class="fas fa-plus text-xs"></i>
                                            </button>
                                        @endif

                                        {{-- Config (only for enabled, non-template plugins with clickApp) --}}
                                        @if ($isEnabled && $groupType !== 'Templates' && ($pluginClass->clickApp() ?? false))
                                            <a href="{{ url()->current() }}?action=config&key={{ $keyExtension }}"
                                                title="{{ gp247_language_render('admin.extension.config') }}"
                                                class="action-btn bg-blue-600 hover:bg-blue-700 focus:ring-blue-500">
                                                <i class="fas fa-cog text-xs"></i>
                                            </a>
                                        @endif

                                        {{-- Disable (enabled non-template) --}}
                                        @if ($isEnabled && $groupType !== 'Templates')
                                            <button type="button"
                                                title="{{ gp247_language_render('admin.extension.disable') }}"
                                                onclick="extensionAction('disable', '{{ $keyExtension }}')"
                                                class="action-btn bg-amber-500 hover:bg-amber-600 focus:ring-amber-400">
                                                <i class="fas fa-power-off text-xs"></i>
                                            </button>
                                        @endif

                                        {{-- Enable (installed, disabled, non-template) --}}
                                        @if ($isInstalled && !$isEnabled && $groupType !== 'Templates')
                                            <button type="button"
                                                title="{{ gp247_language_render('admin.extension.enable') }}"
                                                onclick="extensionAction('enable', '{{ $keyExtension }}')"
                                                class="action-btn bg-blue-600 hover:bg-blue-700 focus:ring-blue-500">
                                                <i class="fas fa-paper-plane text-xs"></i>
                                            </button>
                                        @endif

                                        {{-- Delete data (enabled + not protected) --}}
                                        @if ($isEnabled && !$isProtected)
                                            <button type="button"
                                                title="{{ gp247_language_render('admin.extension.only_delete_data') }}"
                                                onclick="extensionAction('delete', '{{ $keyExtension }}')"
                                                class="action-btn bg-red-600 hover:bg-red-700 focus:ring-red-500">
                                                <i class="fas fa-times text-xs"></i>
                                            </button>
                                        @endif

                                        {{-- Remove files (not protected) --}}
                                        @if (!$isProtected)
                                            <button type="button"
                                                title="{{ gp247_language_render('admin.extension.remove') }}"
                                                onclick="extensionAction('remove', '{{ $keyExtension }}')"
                                                class="action-btn bg-red-600 hover:bg-red-700 focus:ring-red-500">
                                                <i class="fas fa-trash text-xs"></i>
                                            </button>
                                        @endif
                                    @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endif
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
    </div>{{-- /Alpine filter scope --}}

    {{-- Scope legend --}}
    @if ($groupType === 'Plugins')
    <div class="flex flex-wrap gap-x-5 gap-y-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
        <span class="gp247-legend"><span class="gp247-legend-dot gp247-scope-store"></span>{{ gp247_language_render('admin.extension.legend_store') }}</span>
        <span class="gp247-legend"><span class="gp247-legend-dot gp247-scope-global"></span>{{ gp247_language_render('admin.extension.legend_global') }}</span>
    </div>
    @endif
</div>
@endsection

@push('styles')
<style>
.action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 1.875rem; /* 30px */
    width:  1.875rem;
    border-radius: 0.5rem;
    color: #fff;
    transition: background-color 150ms, box-shadow 150ms;
}
.action-btn:focus {
    outline: none;
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--ring-color, #3b82f6);
}

/* Per-store enable toggle (US-PLG-per-store-plugin-enable-list) */
.gp247-switch {
    position: relative;
    display: inline-flex;
    flex: none;
    width: 40px;
    height: 22px;
    vertical-align: middle;
}
.gp247-switch input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    margin: 0;
    opacity: 0;
    cursor: pointer;
    z-index: 1;
}
.gp247-switch-slider {
    position: absolute;
    inset: 0;
    background: #cbd5e1;
    border-radius: 9999px;
    transition: background-color .18s ease;
}
.gp247-switch-slider::before {
    content: "";
    position: absolute;
    top: 3px;
    left: 3px;
    width: 16px;
    height: 16px;
    background: #fff;
    border-radius: 50%;
    box-shadow: 0 1px 2px rgba(0, 0, 0, .25);
    transition: transform .18s ease;
}
.gp247-switch input:checked + .gp247-switch-slider { background: #16a34a; }
.gp247-switch input:checked + .gp247-switch-slider::before { transform: translateX(18px); }
.gp247-switch input:focus-visible + .gp247-switch-slider {
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px #3b82f6;
}
.dark .gp247-switch-slider { background: #4b5563; }

/* Per-store status text + inherited hint */
.gp247-ext-status {
    font-size: 12px;
    font-weight: 500;
    color: #94a3b8;
    white-space: nowrap;
}
.gp247-ext-status.is-on { color: #16a34a; }
.dark .gp247-ext-status { color: #6b7280; }
.dark .gp247-ext-status.is-on { color: #4ade80; }
.gp247-ext-inherit { font-weight: 400; color: #94a3b8; }
.dark .gp247-ext-inherit { color: #6b7280; }

/* Muted "not applicable" cell + global-scope chip */
.gp247-ext-muted { font-size: 14px; color: #cbd5e1; }
.dark .gp247-ext-muted { color: #4b5563; }
.gp247-ext-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 500;
    color: #64748b;
    background: #f1f5f9;
    white-space: nowrap;
}
.dark .gp247-ext-chip { color: #94a3b8; background: #334155; }

/* Scope pill (store / global) */
.gp247-scope {
    display: inline-flex;
    align-items: center;
    padding: 2px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 600;
    text-transform: capitalize;
}
.gp247-scope-store    { color: #0f6e56; background: #e1f5ee; }
.gp247-scope-global   { color: #444441; background: #f1efe8; }
.dark .gp247-scope-store    { color: #5dcaa5; background: rgba(15, 110, 86, .18); }
.dark .gp247-scope-global   { color: #b4b2a9; background: rgba(90, 89, 84, .25); }

/* Scope legend */
.gp247-legend {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #64748b;
}
.dark .gp247-legend { color: #94a3b8; }
.gp247-legend-dot {
    width: 9px;
    height: 9px;
    border-radius: 9999px;
    flex: none;
}
.gp247-legend-dot.gp247-scope-store    { background: #1d9e75; }
.gp247-legend-dot.gp247-scope-global   { background: #888780; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const _urls = {
        enable:    @js($listUrlAction['enable']),
        disable:   @js($listUrlAction['disable']),
        install:   @js($listUrlAction['install']),
        uninstall: @js($listUrlAction['uninstall']),
    };
    const _csrf  = @js(csrf_token());
    const _msgOk = @js(gp247_language_render('admin.msg_change_success'));
    const _msgConfirm = @js(gp247_language_render('action.action_confirm_warning'));

    function notify(type, msg) {
        window.dispatchEvent(new CustomEvent('notify', { detail: { type, message: msg } }));
    }

    function loading(show) {
        const el = document.getElementById('gp247-page-loading');
        if (el) el.style.display = show ? 'flex' : 'none';
    }

    window.extensionAction = async function (action, key, storeId) {
        if (action === 'delete' || action === 'remove') {
            if (!confirm(_msgConfirm)) return;
        }

        const urlMap = {
            install: _urls.install,
            enable:  _urls.enable,
            disable: _urls.disable,
            delete:  _urls.uninstall,
            remove:  _urls.uninstall,
        };

        loading(true);
        const body = new URLSearchParams({ _token: _csrf, key });
        if (action === 'delete') body.append('onlyRemoveData', '1');
        // Per-store enable/disable: scope the toggle to one store (list per-store mode).
        if (storeId !== undefined && storeId !== null && storeId !== '') body.append('store_id', storeId);

        try {
            const res  = await fetch(urlMap[action], {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            const data = await res.json();
            if (parseInt(data.error) === 0) {
                notify('success', data.msg || _msgOk);
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
})();
</script>
@endpush
