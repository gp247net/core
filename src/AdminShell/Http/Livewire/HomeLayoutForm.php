<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\FormComponent;
use GP247\Core\Models\AdminHome;
use Illuminate\Contracts\View\View;

/**
 * Create/edit form for a home-page layout block (ADR-001/005): the block view,
 * its grid size (1–12), sort order and on/off status. The extension is derived
 * from the view's namespace prefix, mirroring the brownfield flow. Gated by
 * `admin_home_layout`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-003
 * @aidlc-adr ADR-001, ADR-005
 */
class HomeLayoutForm extends FormComponent
{
    protected ?string $permission = 'admin_home_layout';

    /** @var array<string, mixed> */
    public array $form = [
        'view' => '',
        'size' => 12,
        'sort' => 0,
        'status' => 1,
    ];

    /**
     * @param string|null $id Home block id to edit; null to create.
     * @return void
     */
    public function mount(?string $id = null): void
    {
        parent::mount();

        if ($id !== null) {
            $block = AdminHome::findOrFail($id);
            $this->editingId = (string) $block->id;
            $this->form = [
                'view' => $block->view,
                'size' => (int) $block->size,
                'sort' => (int) $block->sort,
                'status' => (int) $block->status,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'form.view' => ['required', 'string'],
            'form.size' => ['required', 'numeric', 'min:1', 'max:12'],
            'form.sort' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @param array<string, mixed> $data Sanitised form values.
     * @return void
     */
    protected function persist(array $data): void
    {
        // WHY: the extension namespace is the segment before "::" in the view id
        // (e.g. "gp247-core::component.home_default" → "gp247-core"), matching the
        // brownfield AdminHomeLayoutController::postCreate derivation.
        $extension = explode('::', (string) $data['view'])[0];

        $attributes = [
            'view' => $data['view'],
            'size' => (int) $data['size'],
            'sort' => (int) $data['sort'],
            'extension' => $extension,
            'status' => empty($data['status']) ? 0 : 1,
        ];

        if ($this->editingId !== null) {
            AdminHome::where('id', $this->editingId)->update($attributes);

            return;
        }

        AdminHome::create($attributes);
    }

    /**
     * Save, then return to the list with a flash.
     *
     * @return void
     */
    public function save(): void
    {
        parent::save();

        session()->flash('gp247_admin_success', gp247_language_render('admin.core.save_success'));
        $this->redirectRoute('admin_home_layout.index', navigate: true);
    }

    /**
     * @return array{name: string, url: string}
     */
    protected function listCrumb(): array
    {
        return ['name' => gp247_language_render('admin.admin_home_layout.list'), 'url' => route('admin_home_layout.index')];
    }

    /**
     * Available block views come from the module homepage config (view list).
     *
     * @return View
     */
    public function render(): View
    {
        $views = collect(config('gp247-module.homepage', []))->pluck('view')->filter()->unique()->values()->all();

        return view('gp247-admin::livewire.home-layout-form', [
            'views' => $views,
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render($this->editingId !== null ? 'action.edit' : 'admin.admin_home_layout.add_new_title'),
            'breadcrumb' => $this->listCrumb(),
        ]);
    }
}
