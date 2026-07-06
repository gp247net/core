<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\FormComponent;
use GP247\Core\Models\AdminMenu;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Two-panel admin menu manager (ADR-001/002/005): the create/edit form on the
 * left and the hierarchical menu tree on the right, on a single page — matching
 * the legacy layout (e.g. Language). One Livewire component drives both: editing
 * a node is reached via the edit/{id} route (the form loads on mount, so the URL
 * reflects the node and survives a refresh), and save/cancel navigate back to the
 * base route. The tree supports drag-and-drop reordering and per-node delete.
 * Gated by `admin_menu`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003, US-UI-007
 * @aidlc-adr ADR-001, ADR-002, ADR-005
 */
class MenuManager extends FormComponent
{
    protected ?string $permission = 'admin_menu';

    /** @var array<string, mixed> Editable form payload (bound via wire:model). */
    public array $form = ['title' => '', 'parent_id' => 0, 'uri' => '', 'icon' => '', 'sort' => 0];

    /**
     * Livewire full-page hook: authorize the view, then load the edit form from
     * the edit/{id} route segment (deep link / refresh) or start empty.
     *
     * @param string|null $id Menu id from the edit route (edit/{id}); null to create.
     * @return void
     */
    public function mount(?string $id = null): void
    {
        parent::mount();

        if ($id !== null && $id !== '') {
            $menu = AdminMenu::find($id);
            if ($menu !== null) {
                $this->editingId = (string) $menu->id;
                $this->form = [
                    'title' => $menu->title,
                    'parent_id' => (int) $menu->parent_id,
                    'uri' => (string) $menu->uri,
                    'icon' => (string) $menu->icon,
                    'sort' => (int) $menu->sort,
                ];
            }
            // Stale/invalid id in the path → fall back to create mode.
        }
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

        AdminMenu::create($attributes);
    }

    /**
     * Save, then return to the base route so the URL clears the edit/{id} segment;
     * the success flash is shown on the next mount.
     *
     * @return void
     */
    public function save(): void
    {
        parent::save();

        session()->flash('gp247_admin_success', gp247_language_render('admin.core.save_success'));
        $this->redirectRoute('admin_menu.index', navigate: true);
    }

    /**
     * Delete a leaf menu node. A node with children is kept (remove children first).
     * If the node was open in the edit form, return to the base route to clear the
     * stale edit/{id} URL.
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
            session()->flash('gp247_admin_success', gp247_language_render('admin.menu.delete_success'));
            $this->redirectRoute('admin_menu.index', navigate: true);

            return;
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
