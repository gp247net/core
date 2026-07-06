<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\ResourcePanel;
use GP247\Core\Models\AdminPermission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Two-panel permission manager (ADR-005): add/edit form left + live list right.
 * Extends ResourcePanel so list/search/sort/delete are shared. The URI picker
 * (route groups + filter) is included inline. Gated by `admin_permission`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005, ADR-007
 */
class PermissionManager extends ResourcePanel
{
    protected ?string $permission = 'admin_permission';

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function baseQuery()
    {
        return AdminPermission::query();
    }

    /**
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return ['name', 'slug'];
    }

    /**
     * @return array<int, string>
     */
    protected function sortableColumns(): array
    {
        return ['name', 'slug'];
    }

    /**
     * @return string
     */
    protected function panelView(): string
    {
        return 'gp247-admin::livewire.permission-manager';
    }

    /**
     * @return string
     */
    protected function pageTitle(): string
    {
        return gp247_language_render('admin.permission.title');
    }

    /**
     * @return string
     */
    protected function baseRoute(): string
    {
        return 'admin_permission.index';
    }

    /**
     * @return array<string, mixed>
     */
    protected function formDefaults(): array
    {
        return ['name' => '', 'slug' => '', 'http_uri' => []];
    }

    /**
     * @param AdminPermission $model
     * @return array<string, mixed>
     */
    protected function fillForm($model): array
    {
        return [
            'name'     => (string) $model->name,
            'slug'     => (string) $model->slug,
            'http_uri' => $model->http_uri ? explode(',', $model->http_uri) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $table = (new AdminPermission())->getTable();

        return [
            'form.name'      => ['required', 'string', 'max:50', Rule::unique($table, 'name')->ignore($this->editingId)],
            'form.slug'      => ['required', 'string', 'min:3', 'max:50', 'regex:/^([0-9A-Za-z._\-]+)$/', Rule::unique($table, 'slug')->ignore($this->editingId)],
            'form.http_uri'  => ['array'],
            'form.http_uri.*'=> ['string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ['form.slug.regex' => 'The slug may only contain letters, numbers, dot, dash and underscore.'];
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    protected function persist(array $data): void
    {
        $data['http_uri'] = implode(',', $data['http_uri'] ?? []);

        if ($this->editingId !== null) {
            AdminPermission::where('id', $this->editingId)->update($data);

            return;
        }

        AdminPermission::create($data);
    }

    /**
     * @param int|string $id
     * @return void
     */
    protected function deleteModel($id): void
    {
        $model = AdminPermission::find($id);
        if ($model !== null) {
            $model->delete();
        }
    }

    /**
     * Flat list of route options for the searchable-select component.
     *
     * @return array<int, array{id:string, label:string}>
     */
    public function getRouteOptionsProperty(): array
    {
        $flat = [];
        foreach ($this->routeGroups as $routes) {
            foreach ($routes as $r) {
                $flat[] = ['id' => $r['uri'], 'label' => $r['method'] . '  ' . $r['path']];
            }
        }

        return $flat;
    }

    /**
     * Build the admin route list grouped by prefix for the URI picker.
     *
     * @return array<string, array<int, array{uri:string, method:string, path:string}>>
     */
    public function getRouteGroupsProperty(): array
    {
        $prefix  = defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : '';
        $without = array_map(static fn ($w) => ($prefix ? $prefix . '/' : '') . $w, ['login', 'logout', 'forgot', 'deny', 'locale', 'uploads']);

        $groups = [];
        foreach (Route::getRoutes() as $route) {
            if (! Str::startsWith($route->uri(), (string) $prefix)) {
                continue;
            }
            if (Str::startsWith($route->uri(), $without)) {
                continue;
            }

            $group = ltrim((string) $route->getPrefix(), '/') ?: $prefix;
            $groups[$group]['__wildcard'] = ['uri' => 'ANY::' . $group . '/*', 'method' => 'ANY', 'path' => $group . '/*'];

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $groups[$group][$method . '::' . $route->uri()] = [
                    'uri'    => $method . '::' . $route->uri(),
                    'method' => $method,
                    'path'   => $route->uri(),
                ];
            }
        }

        ksort($groups);

        return array_map('array_values', $groups);
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view($this->panelView(), [
            'rows'         => $this->rows(),
            'routeOptions' => $this->routeOptions,
        ])->layout('gp247-admin::layouts.admin', ['title' => $this->pageTitle()]);
    }
}
