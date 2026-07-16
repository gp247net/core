<?php

namespace GP247\Core\AdminShell\Infrastructure;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\WithPagination;

/**
 * Abstract base for the "two-panel" admin screens (ADR-005): an add/edit form on
 * the left and a live list on the right, on a single page — matching the legacy
 * layout (e.g. Language). One Livewire component drives both: editing a row is
 * reached via the edit/{id} route (the form loads on mount, so the URL reflects
 * the item and survives refresh), and save/cancel navigate back to the base route.
 *
 * Reusable structure: a concrete screen supplies the query, searchable/sortable
 * columns, validation rules, form mapping and the persist/delete operations, plus
 * a per-resource panel view (fields + list cells differ). Mutating actions are
 * Layer-2 authorized (ADR-001) and gp247_clean'd.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-006
 * @aidlc-adr ADR-001, ADR-005
 */
abstract class ResourcePanel extends GP247AdminComponent
{
    use WithPagination;

    /** @var string Free-text search. */
    public string $keyword = '';

    /** @var string Current sort column (empty = default). */
    public string $sortField = '';

    /** @var string Sort direction. */
    public string $sortDir = 'asc';

    /** @var int Rows per page. */
    public int $perPage = 10;

    /** @var array<string, mixed> Add/edit form state. */
    public array $form = [];

    /**
     * Id of the record being edited; null = creating. Set from the edit/{id}
     * route segment in mount() so the edit state lives in the path and survives a
     * refresh / is shareable. Edit is reached by navigating to the edit route;
     * save/cancel navigate back to the base route.
     *
     * @var string|null
     */
    public ?string $editingId = null;

    /**
     * form.* field names holding admin-authored rich HTML (TinyMCE) that must
     * survive save() as-is. gp247_clean() htmlspecialchars-escapes its input,
     * which corrupts real markup (e.g. a Layout Block's `text`); concrete
     * screens with a rich-editor form field must list it here. Mirrors the
     * richFields pattern in FormComponent / WebsiteInfo::RICH_FIELDS.
     *
     * @var array<int, string>
     */
    protected array $richFields = [];

    // --- Contract for concrete screens -------------------------------------

    /**
     * @return \Illuminate\Database\Eloquent\Builder A fresh query for the resource.
     */
    abstract protected function baseQuery();

    /**
     * @return array<int, string> Columns matched by the keyword filter.
     */
    abstract protected function searchable(): array;

    /**
     * @return array<int, string> Columns the user may sort by.
     */
    abstract protected function sortableColumns(): array;

    /**
     * @return array<string, mixed> Empty/default form state.
     */
    abstract protected function formDefaults(): array;

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return array<string, mixed> Form state populated from a record (for edit).
     */
    abstract protected function fillForm($model): array;

    /**
     * @return array<string, mixed> Validation rules (keyed by form.* paths).
     */
    abstract protected function rules(): array;

    /**
     * Persist the sanitised form (insert when editingId is null, else update).
     *
     * @param array<string, mixed> $data
     * @return void
     */
    abstract protected function persist(array $data): void;

    /**
     * Delete a record by id (may enforce guards).
     *
     * @param int|string $id
     * @return void
     */
    abstract protected function deleteModel($id): void;

    /**
     * @return string The per-resource two-panel view name.
     */
    abstract protected function panelView(): string;

    /**
     * @return string Screen title.
     */
    abstract protected function pageTitle(): string;

    /**
     * The base (list/create) route name. The edit route is "<baseRoute>.edit"
     * (registered with an {id} segment). Used to navigate back after save/cancel
     * and to build the per-row edit links in the view.
     *
     * @return string
     */
    abstract protected function baseRoute(): string;

    // --- Shared behaviour ---------------------------------------------------

    /**
     * @return array<int, string> Default [field, direction] sort.
     */
    protected function defaultSort(): array
    {
        return ['id', 'desc'];
    }

    /**
     * Livewire full-page hook: authorize the view, then load the edit form from
     * the edit/{id} route segment (deep link / refresh) or start empty.
     *
     * @param int|string|null $id Record id from the edit route (edit/{id}).
     * @return void
     */
    public function mount($id = null): void
    {
        parent::mount();

        if ($id !== null && $id !== '') {
            $model = $this->baseQuery()->find($id);
            if ($model !== null) {
                $this->editingId = (string) $model->id;
                $this->form = $this->fillForm($model);
                $this->resetValidation();

                return;
            }
            // Stale/invalid id in the path → fall back to create mode.
        }

        $this->resetForm();
    }

    /**
     * Current page of records with keyword filter + validated sort applied.
     *
     * @return LengthAwarePaginator
     */
    protected function rows(): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        if ($this->keyword !== '' && $this->searchable() !== []) {
            $needle = '%' . $this->keyword . '%';
            $columns = $this->searchable();
            $query->where(function ($w) use ($columns, $needle): void {
                foreach ($columns as $i => $column) {
                    $i === 0 ? $w->where($column, 'like', $needle) : $w->orWhere($column, 'like', $needle);
                }
            });
        }

        if (in_array($this->sortField, $this->sortableColumns(), true)) {
            $query->orderBy($this->sortField, $this->sortDir);
        } else {
            [$field, $dir] = $this->defaultSort();
            $query->orderBy($field, $dir);
        }

        return $query->paginate($this->perPage);
    }

    /**
     * Toggle sort on a whitelisted column.
     *
     * @param string $field
     * @return void
     */
    public function setSort(string $field): void
    {
        if (!in_array($field, $this->sortableColumns(), true)) {
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
     * @return void
     */
    public function updatedKeyword(): void
    {
        $this->resetPage();
    }

    /**
     * Clear the form back to create mode.
     *
     * @return void
     */
    public function resetForm(): void
    {
        $this->editingId = null;
        $this->form = $this->formDefaults();
        $this->resetValidation();
    }

    /**
     * Load a record into the form for editing.
     *
     * @param int|string $id
     * @return void
     */
    public function editRow($id): void
    {
        $model = $this->baseQuery()->find($id);
        if ($model === null) {
            return;
        }

        $this->editingId = (string) $model->id;
        $this->form = $this->fillForm($model);
        $this->resetValidation();
    }

    /**
     * Authorize, validate, sanitise and persist the form; refresh + reset.
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     * @throws \Illuminate\Validation\ValidationException When validation fails.
     */
    public function save(): void
    {
        $this->authorizeAction($this->editingId !== null ? 'update' : 'store');
        $this->validate();
        // richFields are excluded so admin-authored rich HTML isn't escaped.
        $this->persist(gp247_clean($this->form, $this->richFields));

        // WHY: navigate back to the base route so the URL clears the edit/{id}
        // segment; the success flash is shown on the next mount (flashNotice).
        session()->flash('gp247_admin_success', gp247_language_render('admin.save_success'));
        $this->redirect(route($this->baseRoute()), navigate: true);
    }

    /**
     * Delete a record (per-row), clearing the form if it was being edited.
     *
     * @param int|string $id
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function delete($id): void
    {
        $this->authorizeAction('delete');
        $this->deleteModel($id);

        // Deleting the row currently open in the edit form → return to the base
        // route so the stale edit/{id} URL is cleared.
        if ((string) $id === (string) $this->editingId) {
            session()->flash('gp247_admin_success', gp247_language_render('admin.delete_success'));
            $this->redirect(route($this->baseRoute()), navigate: true);

            return;
        }

        $this->resetPage();
        $this->notify('success', gp247_language_render('admin.delete_success'));
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view($this->panelView(), [
            'rows' => $this->rows(),
        ])->layout('gp247-admin::layouts.admin', ['title' => $this->pageTitle()]);
    }
}
