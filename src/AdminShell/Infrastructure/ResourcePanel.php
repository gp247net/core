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
    public int $perPage = 15;

    /** @var array<string, mixed> Add/edit form state. */
    public array $form = [];

    /**
     * Store picked on create (root admin) / the record's store on edit (read-only),
     * for store-scoped resources. Unused when the screen is not store-scoped or when
     * multi-store/multi-vendor is not installed. See storeScoped()/currentStore().
     *
     * @var string
     * @aidlc-story US-admin-shell-store-scoped-resource
     * @aidlc-adr admin-shell_store-scoped-resource-panel
     */
    public string $formStoreId = '';

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

    /**
     * Opt-in: keep the list panel state (page/keyword/sort) and the just-saved
     * record on screen when editing/saving, instead of the legacy route
     * navigation + redirect that remounts the whole component and drops that
     * state. A concrete screen sets this true to adopt the in-component
     * master-detail behavior: the per-row edit button calls editRow() (no
     * navigation), save() re-fills the form + toasts (no redirect), and the
     * edit state is mirrored to the URL as ?edit=<id> without a remount so a
     * refresh still restores both the open record and the page. Default false
     * preserves the legacy behavior for screens not yet migrated.
     *
     * A screen opting in must set $this->editingId inside persist() (incl. after
     * a create) so save() can re-fill the form from the persisted row.
     *
     * @var bool
     * @aidlc-story US-AUI-two-panel-state-preservation
     * @aidlc-adr ADR-admin-shell-rbac-two-panel-state-preservation
     */
    protected bool $keepStateOnSave = false;

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

    // --- Store scoping (opt-in; ADR admin-shell_store-scoped-resource-panel) -----

    /**
     * Store-scoping config for this resource, or null (default) when the resource is
     * not store-scoped. Shape: ['display' => 'name'|'title'] — the column the blade
     * uses to label the record next to its store line.
     *
     * @return array<string, mixed>|null
     *
     * @aidlc-story US-admin-shell-store-scoped-resource
     * @aidlc-adr admin-shell_store-scoped-resource-panel
     */
    protected function storeScoped(): ?array
    {
        return null;
    }

    /**
     * Whether store scoping is active: the resource opted in AND multi-store or
     * multi-vendor is installed. When false the panel behaves exactly as before —
     * no picker, no store column, no extra query (single-store parity,
     * RISK-TECH-store-scope-single-store-regression).
     *
     * @return bool
     */
    public function storeScopeActive(): bool
    {
        return $this->storeScoped() !== null
            && function_exists('gp247_store_check_multi_domain_installed')
            && gp247_store_check_multi_domain_installed();
    }

    /**
     * The admin's current store context (ROOT at root admin; the assigned/selected
     * store for a store-admin or the Pro switcher).
     *
     * @return int|string
     */
    protected function storeContext()
    {
        return session('adminStoreId', defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1);
    }

    /**
     * @return bool Whether the context is the root store (no sub-store scope).
     */
    protected function isRootScope(): bool
    {
        $root = defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1;

        return (string) $this->storeContext() === (string) $root;
    }

    /**
     * Livewire hook: when the create picker changes the store, clear the store-
     * dependent form fields (declared in storeScoped()['reset']) so a stale
     * cross-store reference can't linger, and toast the user that related data
     * below changed (ADR admin-shell_store-scoped-resource-panel).
     *
     * @param mixed $value
     * @return void
     */
    public function updatedFormStoreId($value): void
    {
        if (!$this->storeScopeActive() || $this->editingId !== null) {
            return; // store is immutable on edit; nothing to do off create.
        }

        foreach ((array) ($this->storeScoped()['reset'] ?? []) as $field) {
            $current = $this->form[$field] ?? null;
            $this->form[$field] = is_array($current) ? [] : '';
        }
        $this->resetValidation();
        $this->notify('info', gp247_language_render('admin.store.store_changed_notice'));
    }

    /**
     * The store the current form is bound to, for filtering related option lists:
     *  - not active → the context (legacy behaviour, ROOT at root admin);
     *  - editing → the record's store (formStoreId, set by fillForm; immutable);
     *  - creating while scoped → the context (forced);
     *  - creating at root → the picked store, or null until the user picks one.
     *
     * @return int|string|null
     */
    protected function currentStore()
    {
        if (!$this->storeScopeActive()) {
            return $this->storeContext();
        }
        if ($this->editingId !== null && $this->editingId !== '') {
            // Immutable on edit: read the record's own store from the DB, NOT the
            // (client-tamperable) formStoreId property (ADR 1-1 / SR-4).
            $recStore = $this->baseQuery()->whereKey($this->editingId)->value('store_id');

            return $recStore !== null ? $recStore : $this->storeContext();
        }
        if (!$this->isRootScope()) {
            return $this->storeContext();
        }

        return $this->formStoreId !== '' ? $this->formStoreId : null;
    }

    /**
     * Server-authoritative store for a NEW record: the context when scoped (forced),
     * else the validated picked store at root. persist() calls this to set store_id
     * on create ONLY — never on edit (store_id is immutable, ADR 1-1).
     *
     * @return int|string
     */
    protected function resolveCreateStore()
    {
        if (!$this->storeScopeActive() || !$this->isRootScope()) {
            return $this->storeContext();
        }

        return $this->formStoreId;
    }

    /**
     * Localised store title for a record's store_id, for the list "store line".
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function storeLabel($storeId): string
    {
        if ($storeId === null || $storeId === '') {
            return '';
        }
        $titles = \GP247\Core\Models\AdminStore::getListTitle();

        return (string) ($titles[$storeId] ?? $storeId);
    }

    /**
     * Store options (id => title) for the create picker (root admin, store-scoped).
     *
     * @return array<int|string, string>
     */
    public function storeOptions(): array
    {
        return \GP247\Core\Models\AdminStore::getListTitle();
    }

    /**
     * Whether to show the store PICKER (store-scoped create at root admin). When
     * false but store-scoped is active, the blade shows the store read-only.
     *
     * @return bool
     */
    public function showStorePicker(): bool
    {
        return $this->storeScopeActive() && $this->editingId === null && $this->isRootScope();
    }

    /**
     * Localised label of the form's current store (for the read-only display on
     * edit / scoped create).
     *
     * @return string
     */
    public function currentStoreLabel(): string
    {
        $store = $this->currentStore();

        return $this->storeLabel($store === null || $store === '' ? $this->storeContext() : $store);
    }

    /**
     * Livewire full-page hook: authorize the view, then load the edit form from
     * the edit/{id} route segment (deep link / refresh) or start empty.
     *
     * @param int|string|null $id Record id from the edit route (edit/{id}).
     * @return void
     */
    /**
     * Canonical screen path derived from the full-page route, so authorization
     * uses the same http_uri the menu and PermissionMiddleware use (ADR-001
     * Layer-2). Falls back to the base capture when the route cannot be resolved.
     *
     * @return string|null Admin path without scheme/host (e.g. "gp247_admin/order").
     */
    protected function authScreenUri(): ?string
    {
        try {
            $path = parse_url(route($this->baseRoute()), PHP_URL_PATH);
            if ($path !== null && $path !== false) {
                return ltrim($path, '/');
            }
        } catch (\Throwable $e) {
            // Route not registered (e.g. isolated unit context) — use the fallback.
        }

        return parent::authScreenUri();
    }

    public function mount($id = null): void
    {
        parent::mount();

        // PA-A: opted-in screens carry the edit state in the ?edit=<id> query
        // (pushed by editRow without a remount), so a refresh of the base route
        // restores the open record alongside the ?page= that WithPagination
        // restores. The legacy /edit/{id} route param still wins when present.
        if (($id === null || $id === '') && $this->keepStateOnSave) {
            $queryEdit = request()->query('edit');
            if (is_string($queryEdit) && $queryEdit !== '') {
                $id = $queryEdit;
            }
        }

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
        // Store-scoped create: default the picker to the current context store (ROOT
        // at root admin), so a create that never touches the picker keeps the legacy
        // "current store" behaviour; the user changes it to assign to another store.
        $this->formStoreId = $this->storeScopeActive() ? (string) $this->storeContext() : '';
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

        // Opted-in screens: reflect the edit in the URL (?edit=<id>) without a
        // remount so the list state is preserved and a refresh restores it.
        if ($this->keepStateOnSave) {
            $this->syncEditUrl($this->editingId);
        }
    }

    /**
     * Clear the form back to create mode without navigating (opted-in screens),
     * so the "Cancel/Reset" button keeps the list state instead of redirecting
     * to the base route.
     *
     * @return void
     */
    public function cancelEdit(): void
    {
        $this->resetForm();
        $this->syncEditUrl(null);
    }

    /**
     * Reflect the edit state in the URL query (?edit=<id>) without remounting,
     * preserving any existing ?page= so a refresh restores both the open record
     * and the list position (PA-A, ADR two-panel-state-preservation). Pass null
     * to clear the edit param (back to create mode).
     *
     * @param string|null $id
     * @return void
     */
    protected function syncEditUrl(?string $id): void
    {
        // WHY: a history-only update (not Livewire navigate) so the single
        // component instance — and its list state — survives; a refresh then
        // re-enters through mount(), which reads ?edit= for opted-in screens.
        $mutate = $id !== null && $id !== ''
            ? 'u.searchParams.set("edit", ' . json_encode($id) . ');'
            : 'u.searchParams.delete("edit");';

        $this->js('const u = new URL(window.location.href); ' . $mutate . ' window.history.replaceState({}, "", u);');
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

        // Validate the screen's rules together with the store-pick rule (when a
        // store-scoped resource is being created at root admin), in ONE pass so all
        // errors surface at once. Scoped context / edit / non-store-scoped screens
        // add no store rule (ADR admin-shell_store-scoped-resource-panel).
        $rules = $this->rules();
        $messages = [];
        if ($this->storeScopeActive() && $this->editingId === null && $this->isRootScope()) {
            $rules['formStoreId'] = ['required', \Illuminate\Validation\Rule::exists((new \GP247\Core\Models\AdminStore)->getTable(), 'id')];
            $messages['formStoreId.required'] = gp247_language_render('admin.store.select_store_required');
            $messages['formStoreId.exists']   = gp247_language_render('admin.store.select_store_required');
        }
        $this->validate($rules, $messages);
        // richFields are excluded so admin-authored rich HTML isn't escaped.
        $this->persist(gp247_clean($this->form, $this->richFields));

        // Opted-in screens (ADR two-panel-state-preservation): stay in place so
        // the list keeps its page/keyword/sort and the just-saved record remains
        // on the form. persist() sets $this->editingId (incl. after a create) so
        // we can re-fill from the persisted row and reflect it in the URL.
        if ($this->keepStateOnSave) {
            if ($this->editingId !== null && $this->editingId !== '') {
                $model = $this->baseQuery()->find($this->editingId);
                if ($model !== null) {
                    $this->form = $this->fillForm($model);
                }
                $this->syncEditUrl($this->editingId);
            }
            $this->resetValidation();
            $this->notify('success', gp247_language_render('admin.save_success'));

            return;
        }

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

        // Deleting the row currently open in the edit form.
        if ((string) $id === (string) $this->editingId) {
            // Opted-in screens: clear the form in place (and the ?edit= URL)
            // instead of redirecting, keeping the list state.
            if ($this->keepStateOnSave) {
                $this->resetForm();
                $this->syncEditUrl(null);
                $this->notify('success', gp247_language_render('admin.delete_success'));

                return;
            }

            // Legacy: return to the base route so the stale edit/{id} URL clears.
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
