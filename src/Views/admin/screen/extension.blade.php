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
                        <td colspan="7" class="px-4 py-12 text-center">
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
                                <td colspan="7" class="px-4 py-3 text-xs text-red-600 dark:text-red-400">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>{{ $errorMsg ?? '' }}
                                </td>
                            </tr>
                        @else
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
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

                            {{-- Version --}}
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                {{ $pluginClass->version ?? '' }}
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
                                </div>
                            </td>
                        </tr>
                        @endif
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
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

    window.extensionAction = async function (action, key) {
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
