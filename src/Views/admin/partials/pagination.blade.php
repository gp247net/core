{{--
    TailAdmin pagination for Livewire DataTableComponent (ADR-005).

    Driven by WithPagination's wire:click handlers (previousPage/nextPage/gotoPage)
    rather than URLs, so it works inside the shared livewire/update lifecycle.
    Renders nothing when there is only a single page.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002
    @aidlc-adr ADR-005

    Variables (provided by $paginator->links()):
      - $paginator (LengthAwarePaginator): the current page set.
--}}
@if ($paginator->hasPages())
    <nav class="flex items-center justify-between text-sm" role="navigation">
        <p class="text-gray-500 dark:text-gray-400">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} / {{ $paginator->total() }}
        </p>

        <div class="flex items-center gap-1">
            <button type="button" wire:click="previousPage" wire:loading.attr="disabled"
                @disabled($paginator->onFirstPage())
                class="rounded-lg border border-gray-300 px-3 py-1.5 transition hover:bg-gray-100 disabled:opacity-40 dark:border-gray-600 dark:hover:bg-gray-700">
                <i class="fas fa-angle-left"></i>
            </button>

            <span class="px-2 text-gray-600 dark:text-gray-300">
                {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
            </span>

            <button type="button" wire:click="nextPage" wire:loading.attr="disabled"
                @disabled(! $paginator->hasMorePages())
                class="rounded-lg border border-gray-300 px-3 py-1.5 transition hover:bg-gray-100 disabled:opacity-40 dark:border-gray-600 dark:hover:bg-gray-700">
                <i class="fas fa-angle-right"></i>
            </button>
        </div>
    </nav>
@endif
