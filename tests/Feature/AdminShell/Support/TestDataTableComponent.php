<?php

namespace GP247\Core\Tests\Feature\AdminShell\Support;

use GP247\Core\AdminShell\Infrastructure\DataTableComponent;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Concrete DataTableComponent backed by an in-memory dataset so the base
 * behaviour (sort toggle, keyword/page reset, bulk-delete authorization) can be
 * exercised through the real Livewire lifecycle without the GP247 database.
 *
 * It overrides rows() and deleteRows() — the only two DB touch points — so the
 * brownfield ModelTrait path is never hit in tests.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002
 * @aidlc-adr ADR-005
 */
class TestDataTableComponent extends DataTableComponent
{
    protected ?string $permission = 'admin_test';

    /** @var array<int, array{id:int,name:string}> */
    public array $items = [
        ['id' => 1, 'name' => 'Bravo'],
        ['id' => 2, 'name' => 'Alpha'],
    ];

    /** @var array<int, mixed> Captured ids from the last deleteRows() call. */
    public array $deletedIds = [];

    /**
     * @return null Unused: rows()/deleteRows() are overridden below.
     */
    protected function query()
    {
        return null;
    }

    /**
     * @return array<string, string> Column field => label map.
     */
    protected function columns(): array
    {
        return ['name' => 'Name'];
    }

    /**
     * Build an in-memory paginator honouring the current keyword/sort state.
     *
     * @return LengthAwarePaginator
     */
    protected function rows(): LengthAwarePaginator
    {
        /** @var Collection $rows */
        $rows = collect($this->items);

        if ($this->keyword !== '') {
            $needle = strtolower($this->keyword);
            $rows = $rows->filter(
                static fn (array $i): bool => str_contains(strtolower($i['name']), $needle)
            )->values();
        }

        if ($this->sortField !== '') {
            $rows = $this->sortDir === 'desc'
                ? $rows->sortByDesc($this->sortField)->values()
                : $rows->sortBy($this->sortField)->values();
        }

        return new LengthAwarePaginator(
            $rows,
            $rows->count(),
            $this->perPage,
            Paginator::resolveCurrentPage(),
            ['path' => '/']
        );
    }

    /**
     * @param array<int, mixed> $ids Ids selected for deletion.
     * @return void
     */
    protected function deleteRows(array $ids): void
    {
        $this->deletedIds = $ids;
    }
}
