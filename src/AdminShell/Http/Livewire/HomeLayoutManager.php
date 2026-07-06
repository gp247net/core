<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\ResourcePanel;
use GP247\Core\Models\AdminHome;
use Illuminate\Contracts\View\View;

/**
 * Home-page layout block manager — two-panel screen (form left, list right)
 * following the ResourcePanel pattern (ADR-005, ADR-007). Replaces the separate
 * HomeLayoutList + HomeLayoutForm pair with a single-page experience matching
 * the currency/brand manager style. Gated by `admin_home_layout`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002, US-UI-003
 * @aidlc-adr ADR-001, ADR-005, ADR-007
 */
class HomeLayoutManager extends ResourcePanel
{
    protected ?string $permission = 'admin_home_layout';

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function baseQuery()
    {
        return AdminHome::query();
    }

    /**
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return ['view'];
    }

    /**
     * @return array<int, string>
     */
    protected function sortableColumns(): array
    {
        return ['view', 'size', 'sort', 'status'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function defaultSort(): array
    {
        return ['sort', 'desc'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formDefaults(): array
    {
        return ['view' => '', 'size' => 12, 'sort' => 0, 'status' => 1];
    }

    /**
     * @param AdminHome $model
     * @return array<string, mixed>
     */
    protected function fillForm($model): array
    {
        return [
            'view'   => (string) $model->view,
            'size'   => (int) $model->size,
            'sort'   => (int) $model->sort,
            'status' => (int) $model->status,
        ];
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
     * @param array<string, mixed> $data
     * @return void
     */
    protected function persist(array $data): void
    {
        // WHY: extension namespace is the segment before "::" in the view id,
        // matching the brownfield AdminHomeLayoutController derivation.
        $extension = explode('::', (string) $data['view'])[0];

        $attributes = [
            'view'      => $data['view'],
            'size'      => (int) $data['size'],
            'sort'      => (int) $data['sort'],
            'extension' => $extension,
            'status'    => empty($data['status']) ? 0 : 1,
        ];

        if ($this->editingId !== null) {
            AdminHome::where('id', $this->editingId)->update($attributes);
        } else {
            AdminHome::create($attributes);
        }
    }

    /**
     * @param int|string $id
     * @return void
     */
    protected function deleteModel($id): void
    {
        $model = AdminHome::find($id);
        if ($model !== null) {
            $model->delete();
        }
    }

    /**
     * @return string
     */
    protected function panelView(): string
    {
        return 'gp247-admin::livewire.home-layout-manager';
    }

    /**
     * @return string
     */
    protected function pageTitle(): string
    {
        return gp247_language_render('admin.admin_home_layout.list');
    }

    /**
     * @return string
     */
    protected function baseRoute(): string
    {
        return 'admin_home_layout.index';
    }

    /**
     * @return View
     */
    public function render(): View
    {
        $views = collect(config('gp247-module.homepage', []))->pluck('view')->filter()->unique()->values()->all();

        return view($this->panelView(), [
            'rows'  => $this->rows(),
            'views' => $views,
        ])->layout('gp247-admin::layouts.admin', ['title' => $this->pageTitle()]);
    }
}
