<?php

namespace GP247\Core\AdminShell\Infrastructure;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\WithPagination;

/**
 * Abstract list/table base for admin Livewire screens (ADR-005).
 *
 * Manages keyword search, single-column sort, page size and pagination state,
 * plus per-row and bulk deletion with a deny-by-default guard hook. Concrete
 * screens stay declarative: they provide query(), columns() and a few small
 * hooks (searchable(), with(), defaultSort(), listView(), viewData(),
 * guardedIds()) instead of re-implementing the query/delete/render plumbing.
 *
 * Read interactions are covered by Layer-1 (URI-based persistent middleware);
 * the mutating actions delete()/bulkDelete() re-check via Layer-2 (ADR-001).
 *
 * The two DB touch points rows() and deleteRows() remain overridable so tests
 * can supply in-memory data without the GP247 database.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002
 * @aidlc-adr ADR-001, ADR-005
 */
abstract class DataTableComponent extends GP247AdminComponent
{
    use WithPagination;

    /** @var string Free-text search applied across searchable() columns. */
    public string $keyword = '';

    /** @var string Currently sorted column (empty = defaultSort()). */
    public string $sortField = '';

    /** @var string Sort direction: "asc" or "desc". */
    public string $sortDir = 'asc';

    /** @var int Rows per page. */
    public int $perPage = 20;

    /** @var array<int, mixed> Row ids selected for bulk actions. */
    public array $selected = [];

    /**
     * Language key for the full-page screen title (resolved via
     * gp247_language_render at render time). When set, render() wraps the view
     * in the admin layout.
     *
     * @var string|null
     */
    protected ?string $titleKey = null;

    /**
     * Literal full-page title (fallback when no $titleKey is declared).
     *
     * @var string|null
     */
    protected ?string $screenTitle = null;

    /**
     * Legacy/optional page title kept for embeddable instances that wrap the
     * layout without a custom view (see TestDataTableComponent).
     *
     * @var string|null
     */
    protected ?string $pageTitle = null;

    /** @var string Language key for the "row is protected" warning. */
    protected string $guardedKey = 'admin.protected_item';

    /** @var string Layout used for full-page rendering. */
    protected string $adminLayout = 'gp247-admin::layouts.admin';

    /**
     * A fresh model used to build the listing query.
     *
     * @return \Illuminate\Database\Eloquent\Model|mixed
     */
    abstract protected function query();

    /**
     * Sortable column definitions (field => label). Doubles as the sort
     * whitelist so setSort() cannot be driven to arbitrary columns.
     *
     * @return array<string, string>
     */
    abstract protected function columns(): array;

    /**
     * Columns matched by the keyword filter (OR'd together).
     *
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return [];
    }

    /**
     * Relations to eager-load on the listing query. Named relations() (not
     * with()) because "with" collides with a reserved Livewire method name.
     *
     * @return array<int, string>
     */
    protected function relations(): array
    {
        return [];
    }

    /**
     * Default [field, direction] order when no sort column is active.
     *
     * @return array{0: string, 1: string}
     */
    protected function defaultSort(): array
    {
        return ['id', 'desc'];
    }

    /**
     * Ids that may never be deleted (built-in/protected rows). Compared as
     * strings so both int and string primary keys are handled.
     *
     * @return array<int, mixed>
     */
    protected function guardedIds(): array
    {
        return [];
    }

    /**
     * Per-resource list view; empty falls back to the generic data-table
     * partial (used by embedded/test components).
     *
     * @return string
     */
    protected function listView(): string
    {
        return '';
    }

    /**
     * Extra variables passed to the list view (e.g. guard id sets, label maps).
     *
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        return [];
    }

    /**
     * Warning shown when a guarded row deletion is attempted.
     *
     * @return string
     */
    protected function guardedMessage(): string
    {
        return gp247_language_render($this->guardedKey);
    }

    /**
     * Toggle sort: same field flips direction, a new field sorts ascending.
     * Resets to the first page so the user sees the top of the new ordering.
     *
     * @param string $field The column field to sort by.
     * @return void
     */
    public function setSort(string $field): void
    {
        // WHY: $field feeds an orderBy column name (not a bound value); only
        // declared columns are accepted to prevent SQL injection via setSort().
        if (!array_key_exists($field, $this->columns())) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    /**
     * Livewire hook: reset paging whenever the search keyword changes.
     *
     * @return void
     */
    public function updatedKeyword(): void
    {
        $this->resetPage();
    }

    /**
     * Livewire hook: reset paging whenever the page size changes.
     *
     * @return void
     */
    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * Delete a single row after Layer-2 authorization, skipping guarded ids.
     *
     * @param int|string $id
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function delete($id): void
    {
        $this->authorizeAction('delete');

        if ($this->isGuarded($id)) {
            $this->notify('warning', $this->guardedMessage());

            return;
        }

        $this->deleteRows([$id]);
        $this->resetPage();
        $this->notify('success', gp247_language_render('admin.delete_success'));
    }

    /**
     * Delete the selected rows after Layer-2 authorization.
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function bulkDelete(): void
    {
        $this->authorizeAction('delete');

        if ($this->selected === []) {
            return;
        }

        $this->deleteRows($this->selected);
        $this->selected = [];
        $this->resetPage();
        $this->notify('success', gp247_language_render('admin.delete_selected_success'));
    }

    /**
     * Build the paginated result set from the current keyword/sort state.
     * Overridable so tests can supply in-memory data.
     *
     * @return LengthAwarePaginator
     */
    protected function rows(): LengthAwarePaginator
    {
        $query = $this->query()->newQuery();

        if ($this->relations() !== []) {
            $query->with($this->relations());
        }

        if ($this->keyword !== '' && $this->searchable() !== []) {
            $needle = '%' . $this->keyword . '%';
            $columns = $this->searchable();
            $query->where(function ($w) use ($columns, $needle): void {
                foreach ($columns as $i => $column) {
                    $i === 0 ? $w->where($column, 'like', $needle) : $w->orWhere($column, 'like', $needle);
                }
            });
        }

        if ($this->sortField !== '' && array_key_exists($this->sortField, $this->columns())) {
            $query->orderBy($this->sortField, $this->sortDir);
        } else {
            [$field, $dir] = $this->defaultSort();
            $query->orderBy($field, $dir);
        }

        return $query->paginate($this->perPage);
    }

    /**
     * Delete rows by id, filtering out guarded ids first. Per-model deletion
     * (get()->each->delete()) so model events / pivot cleanup still fire.
     * Overridable so tests avoid the database.
     *
     * @param array<int, mixed> $ids Selected row ids.
     * @return void
     */
    protected function deleteRows(array $ids): void
    {
        $ids = $this->stripGuarded($ids);

        if ($ids === []) {
            return;
        }

        $this->query()->newQuery()->whereIn('id', $ids)->get()->each->delete();
    }

    /**
     * @param int|string $id
     * @return bool Whether the id is in the guarded set.
     */
    protected function isGuarded($id): bool
    {
        return in_array((string) $id, array_map('strval', $this->guardedIds()), true);
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, mixed> Ids with guarded entries removed.
     */
    protected function stripGuarded(array $ids): array
    {
        $guarded = array_map('strval', $this->guardedIds());

        return array_values(array_filter(
            $ids,
            static fn ($id): bool => !in_array((string) $id, $guarded, true)
        ));
    }

    /**
     * Render the list. A concrete listView() renders the per-resource view;
     * otherwise the generic data-table partial is used. Wrapped in the admin
     * layout when a screen title is present.
     *
     * @return View
     */
    public function render(): View
    {
        $data = array_merge([
            'rows' => $this->rows(),
            'columns' => $this->columns(),
        ], $this->viewData());

        $view = view($this->listView() !== '' ? $this->listView() : 'gp247-admin::partials.data-table', $data);

        $title = $this->titleKey !== null
            ? gp247_language_render($this->titleKey)
            : ($this->screenTitle ?? $this->pageTitle);
        if ($title !== null) {
            $view->layout($this->adminLayout, ['title' => $title]);
        }

        return $view;
    }
}
