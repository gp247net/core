{{--
    Per-key store-scope badge for config screens that do NOT use the generic
    config-form table (e.g. EmailSettingsForm's two-card layout). At a sub-store
    scope it shows whether the key's value is inherited from the shared (ROOT/GLOBAL)
    config or overridden ("Custom") with a reset-to-shared action. Renders nothing at
    the base scope. The generic config-form.blade renders its own inline equivalent.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-AUI-core-config-store-scope
    @aidlc-adr admin-shell_core-config-store-scope

    Variables:
      - $key (string) — the config key
      - $subStoreScope (bool) — a sub-store (override) scope is selected
--}}
@if (!empty($subStoreScope))
    <div class="mt-1 flex items-center gap-2">
        @if ($this->isInherited($key))
            <span class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">{{ gp247_language_quickly('admin.store.value_inherited', 'Inherited from shared') }}</span>
        @else
            {{-- Overridden for this store: a single reset action (icon + label) that
                 deletes the override so the key falls back to the default config. --}}
            <button type="button" wire:click="resetToGlobal('{{ $key }}')" data-testid="config-form-reset-{{ $key }}"
                class="inline-flex items-center gap-2 text-xs text-blue-600 hover:underline dark:text-blue-400">
                <i class="fas fa-undo"></i> {{ gp247_language_quickly('admin.store.use_shared', 'Reset to default') }}
            </button>
        @endif
    </div>
@endif
