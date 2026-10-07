<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\FormComponent;
use GP247\Core\AdminShell\Infrastructure\HasValidationLabels;
use GP247\Core\AdminShell\Infrastructure\SyncsEditQuery;
use GP247\Core\Models\AdminMenu;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Two-panel admin menu manager (ADR-001/002/005): the create/edit form on the
 * left and the hierarchical menu tree on the right, on a single page — matching
 * the legacy layout (e.g. Language). One Livewire component drives both: editing
 * a node loads it in place (editRow, no navigation) and highlights it in the
 * tree; save/cancel/delete stay on the screen. The open node is mirrored to
 * ?edit=<id> so a refresh restores it; the edit/{id} route still deep-links.
 * The tree supports drag-and-drop reordering and per-node delete.
 * Gated by `admin_menu`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003, US-UI-007, US-AUI-two-panel-state-preservation
 * @aidlc-adr ADR-001, ADR-002, ADR-005, ADR-admin-shell-rbac-two-panel-state-preservation
 */
class MenuManager extends FormComponent
{
    use HasValidationLabels;
    use SyncsEditQuery;

    protected ?string $permission = 'admin_menu';

    /** @var array<string, mixed> Editable form payload (bound via wire:model). */
    public array $form = self::EMPTY_FORM;

    /** Empty create-mode form. */
    private const EMPTY_FORM = ['title' => '', 'parent_id' => 0, 'uri' => '', 'icon' => '', 'sort' => 0];

    /**
     * Livewire full-page hook: authorize the view, then load the edit form from
     * the edit/{id} route segment or the ?edit= query (deep link / refresh), or
     * start empty.
     *
     * @param string|null $id Menu id from the edit route (edit/{id}); null to create.
     * @return void
     */
    public function mount(?string $id = null): void
    {
        parent::mount();

        if ($id === null || $id === '') {
            $queryEdit = request()->query('edit');
            $id = is_string($queryEdit) && $queryEdit !== '' ? $queryEdit : null;
        }

        if ($id !== null) {
            // Stale/invalid id → stays in create mode.
            $this->loadNode($id);
        }
    }

    /**
     * Load a node into the form for editing, in place (no navigation), and
     * mirror it to ?edit=<id>.
     *
     * @param int|string $id
     * @return void
     */
    public function editRow($id): void
    {
        if ($this->loadNode($id)) {
            $this->syncEditUrl($this->editingId);
        }
    }

    /**
     * Clear the form back to create mode without navigating.
     *
     * @return void
     */
    public function cancelEdit(): void
    {
        $this->resetForm();
        $this->syncEditUrl(null);
    }

    /**
     * @return void
     */
    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->form = self::EMPTY_FORM;
        $this->resetValidation();
    }

    /**
     * Fill the form from a node; returns false (form untouched) when it is gone.
     *
     * @param int|string $id
     * @return bool
     */
    protected function loadNode($id): bool
    {
        $menu = AdminMenu::find($id);
        if ($menu === null) {
            return false;
        }

        $this->editingId = (string) $menu->id;
        $this->form = [
            'title' => $menu->title,
            'parent_id' => (int) $menu->parent_id,
            'uri' => (string) $menu->uri,
            'icon' => (string) $menu->icon,
            'sort' => (int) $menu->sort,
        ];
        $this->resetValidation();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'form.title' => ['required', 'string', 'max:255'],
            'form.parent_id' => ['nullable'],
            'form.uri' => ['nullable', 'string', 'max:255'],
            'form.icon' => ['nullable', 'string', 'max:255'],
            'form.sort' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Reuse the existing v1 menu label keys for validator attributes.
     *
     * @return array<string, string>
     */
    protected function attributeLabels(): array
    {
        return [
            'form.title' => 'admin.menu.field_title',
            'form.parent_id' => 'admin.menu.parent',
            'form.uri' => 'admin.menu.uri',
            'form.icon' => 'admin.menu.icon_field',
            'form.sort' => 'admin.sort',
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    protected function persist(array $data): void
    {
        $attributes = [
            'title' => $data['title'],
            'parent_id' => (int) ($data['parent_id'] ?? 0),
            'uri' => $data['uri'] ?? '',
            'icon' => $data['icon'] ?? '',
            'sort' => (int) ($data['sort'] ?? 0),
        ];

        if ($this->editingId !== null) {
            AdminMenu::where('id', $this->editingId)->update($attributes);

            return;
        }

        $created = AdminMenu::create($attributes);
        // Expose the new id so save() keeps it open for editing.
        $this->editingId = (string) $created->id;
    }

    /**
     * Save and stay on the screen: the saved node remains open in the form and
     * highlighted in the tree (no redirect); the success toast comes from the parent.
     *
     * @return void
     */
    public function save(): void
    {
        parent::save();

        // The parent already toasted success; keep the saved node open in place.
        if ($this->editingId !== null && $this->loadNode($this->editingId)) {
            $this->syncEditUrl($this->editingId);
        }
    }

    /**
     * Delete a leaf menu node. A node with children is kept (remove children first).
     * If the node was open in the edit form, the form is cleared in place.
     *
     * @param int|string $id
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function delete($id): void
    {
        $this->authorizeAction('delete');

        if (AdminMenu::where('parent_id', $id)->exists()) {
            $this->notify('warning', gp247_language_render('admin.menu.has_children'));

            return;
        }

        AdminMenu::destroy($id);

        if ((string) $id === (string) $this->editingId) {
            $this->resetForm();
            $this->syncEditUrl(null);
        }

        $this->notify('success', gp247_language_render('admin.menu.delete_success'));
    }

    /**
     * Persist a drag-reordered sibling list (and parent_id). The drag handler sends
     * ids top-to-bottom; the tree and the sidebar both order by `sort` DESC (see
     * AdminMenu::getListAll), so the topmost item must get the highest sort to keep
     * the same visual order across both. Called from the client drag handler.
     *
     * @param int|string        $parentId The parent the siblings belong to.
     * @param array<int, mixed> $ids      Ordered child ids (top to bottom).
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function reorder($parentId, array $ids): void
    {
        $this->authorizeAction('update');

        $ids = array_values($ids);
        $count = count($ids);
        foreach ($ids as $index => $id) {
            AdminMenu::where('id', $id)->update([
                'parent_id' => (int) $parentId,
                'sort' => $count - $index,
            ]);
        }

        $this->notify('success', gp247_language_render('admin.menu.order_updated'));
    }

    /**
     * Parent options: "Root" + every menu (indented), excluding the node being
     * edited so it can't become its own parent.
     *
     * @return array<int|string, string>
     */
    public function getParentOptionsProperty(): array
    {
        $tree = (new AdminMenu())->getTree();

        if ($this->editingId !== null) {
            unset($tree[(int) $this->editingId], $tree[$this->editingId]);
        }

        return [0 => gp247_language_render('admin.menu.parent_root')] + $tree;
    }

    /**
     * Menu rows grouped by parent_id for recursive rendering. Ordered by `sort`
     * DESC to mirror the left sidebar (AdminMenu::getListAll), so the manager tree
     * shows nodes in the same order users see in the navigation.
     *
     * @return Collection<int|string, Collection<int, AdminMenu>>
     */
    protected function grouped(): Collection
    {
        return AdminMenu::orderBy('sort', 'desc')->get()->groupBy('parent_id');
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('gp247-admin::livewire.menu-manager', [
            'parentOptions' => $this->parentOptions,
            'grouped' => $this->grouped(),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('admin.menu.title'),
        ]);
    }
}
