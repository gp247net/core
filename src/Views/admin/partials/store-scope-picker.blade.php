{{--
    Reusable store-scope picker for ResourcePanel screens (P3 — shared admin UI in
    core). Renders nothing unless the screen opted into storeScoped() AND a
    multi-store/multi-vendor plugin is installed. On create at root it shows the
    store SELECT (formStoreId, live so updatedFormStoreId can reset dependent
    fields); on edit or in a scoped context it shows the bound store read-only.

    Include as the FIRST field of the form:
        @include('gp247-admin::partials.store-scope-picker', ['testid' => '<screen>-store-select'])

    @aidlc-unit admin-shell
    @aidlc-story US-SADM-store-content-assignment
    @aidlc-adr admin-shell_store-scoped-resource-panel
--}}
@if ($this->storeScopeActive())
    <div class="rounded-lg border border-blue-200 bg-blue-50/60 p-3 dark:border-blue-900 dark:bg-blue-900/10">
        <label class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-200">
            {{ gp247_language_render('admin.store.scope_label') }}
        </label>
        @if ($this->showStorePicker())
            <select wire:model.live="formStoreId" data-testid="{{ $testid ?? 'store-select' }}"
                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <option value="">— {{ gp247_language_render('admin.store.select_store') }} —</option>
                @foreach ($this->storeOptions() as $sid => $stitle)
                    <option value="{{ $sid }}">{{ $stitle }}</option>
                @endforeach
            </select>
            @error('formStoreId') <span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
        @else
            <div class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <i class="fas fa-store text-gray-400"></i> {{ $this->currentStoreLabel() }}
            </div>
        @endif
    </div>
@endif
