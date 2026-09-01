{{--
    Reusable "store line" shown under a list row's title so each store-scoped
    record is labelled by its owning store in the root admin (P3 — shared admin UI
    in core). Renders nothing unless the screen is store-scoped and a plugin is
    installed.

    Include under the row title cell:
        @include('gp247-admin::partials.store-scope-line', ['storeId' => $row->store_id])

    @aidlc-unit admin-shell
    @aidlc-story US-SADM-store-content-assignment
    @aidlc-adr admin-shell_store-scoped-resource-panel
--}}
@if ($this->storeScopeActive())
    <span class="mt-0.5 block text-xs font-normal text-gray-400 dark:text-gray-500">
        <i class="fas fa-store"></i> {{ $this->storeLabel($storeId ?? null) }}
    </span>
@endif
