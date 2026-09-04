{{--
    Settings table for a key/value admin_config group (ADR-005). Two columns —
    "Setting | Value" — matching the legacy admin look. Values edit inline and
    persist live (checkbox toggle / number-blur / text-blur), no submit button.

    Per-store scope (ADR plugin-manager_per-store-plugin-config): when $storeScope is
    true the screen shows a scope picker (root admin) or the bound store (store-admin);
    at a sub-store scope ($subStoreScope) each row shows whether the value is its own
    ("riêng") or inherited from the shared config, with a reset-to-shared action, plus
    a per-store enable toggle when the plugin declares one.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-005, US-AUI-config-form-store-scope
    @aidlc-adr ADR-005, plugin-manager_per-store-plugin-config

    Variables:
      - $configs (Collection of AdminConfig)
      - $heading (string) — first column header
      - $types (array<string,string>) — key => bool|number|select|password|text
      - $options (array<string,array>) — key => [value => label, ...], for "select" keys
      - $hints (array<string,string>) — key => inline unit hint beside the label (e.g. a currency code)
      - $storeScope (bool) — per-store scope UI is active
      - $subStoreScope (bool) — a sub-store (override) scope is selected
--}}
<div class="max-w-3xl">
    @if ($storeScope)
        <div class="mb-4 rounded-xl border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900 dark:bg-blue-900/10">
            <label class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-200">
                <i class="fas fa-store text-gray-400"></i> {{ gp247_language_render('admin.store.scope_label') }}
            </label>
            @if ($this->showStorePicker())
                <select wire:model.live="formStoreId" data-testid="config-form-store-select"
                    class="w-full max-w-sm rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">— {{ gp247_language_quickly('admin.store.scope_global', 'Shared config') }} —</option>
                    @foreach ($this->storeOptions() as $sid => $stitle)
                        <option value="{{ $sid }}">{{ $stitle }}</option>
                    @endforeach
                </select>
            @else
                <div class="max-w-sm rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    {{ $this->currentStoreLabel() }}
                </div>
            @endif

            @if ($this->showStoreEnableToggle())
                <div class="mt-3 flex items-center justify-between rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
                    <span class="text-sm text-gray-700 dark:text-gray-200">
                        <i class="fas fa-power-off text-gray-400"></i> {{ gp247_language_quickly('admin.store.plugin_enable_here', 'Enable this plugin for this store') }}
                    </span>
                    {{-- Same proven toggle markup as config-field type=toggle (classes known to the build). --}}
                    <label class="relative inline-flex h-6 w-11 cursor-pointer items-center">
                        <input type="checkbox" wire:model.live="storeEnabled" data-testid="config-form-store-enable" class="peer sr-only">
                        <span class="absolute inset-0 rounded-full bg-gray-200 transition-colors peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-500 dark:bg-gray-600"></span>
                        <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                    </label>
                </div>
            @endif
        </div>
    @endif

    {{-- Save feedback is shown by the global top-right notifications block
         (<x-gp247::toast>), so there is no inline notice pushing the layout. --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $heading }}</th>
                    <th class="w-64 px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.value') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($configs as $i => $config)
                    @php $type = $types[$config->key] ?? 'text'; @endphp
                    <tr wire:key="cfg-{{ $config->key }}-{{ $subStoreScope ? 'sub' : 'base' }}" class="{{ $i % 2 ? 'bg-gray-50/50 dark:bg-gray-800/40' : 'bg-white dark:bg-gray-800' }}">
                        <td class="px-5 py-3 align-middle text-sm text-gray-700 dark:text-gray-200">
                            {!! $config->detail ? gp247_language_render($config->detail) : e($config->key) !!}
                            @if (!empty($hints[$config->key] ?? ''))
                                <span class="ml-1 text-xs font-normal text-gray-400 dark:text-gray-500">{{ $hints[$config->key] }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 align-middle">
                            @include('gp247-admin::partials.config-field', ['key' => $config->key, 'type' => $type, 'options' => $options[$config->key] ?? []])
                            @if ($subStoreScope)
                                <div class="mt-1 flex items-center gap-2">
                                    @if ($this->isInherited($config->key))
                                        <span class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">{{ gp247_language_quickly('admin.store.value_inherited', 'Inherited from shared') }}</span>
                                    @else
                                        <span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">{{ gp247_language_quickly('admin.store.value_own', 'Custom') }}</span>
                                        <button type="button" wire:click="resetToGlobal('{{ $config->key }}')" data-testid="config-form-reset-{{ $config->key }}"
                                            class="text-xs text-blue-600 hover:underline dark:text-blue-400">{{ gp247_language_quickly('admin.store.use_shared', 'Use shared config') }}</button>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.no_settings') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
