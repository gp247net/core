<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\HasValidationLabels;
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
 * @aidlc-adr ADR-001, ADR-005, ADR-007, ADR-admin-shell-rbac-permission-route-picker-scoping
 */
class PermissionManager extends ResourcePanel
{
    use HasValidationLabels;

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
        return array_merge($this->localizedRuleMessages(), [
            'form.slug.regex' => 'The slug may only contain letters, numbers, dot, dash and underscore.',
        ]);
    }

    /**
     * Reuse the existing v1 permission label keys for validator attributes.
     *
     * @return array<string, string>
     */
    protected function attributeLabels(): array
    {
        return [
            'form.name' => 'admin.permission.name',
            'form.slug' => 'admin.permission.slug',
            'form.http_uri' => 'admin.permission.allowed_routes',
        ];
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
     * Route classification (ADR-admin-shell-rbac-permission-route-picker-scoping):
     * - Always-allow util routes (login/logout/forgot/deny/locale) are default pass-through
     *   (Permission::listPath/RouteDefaultPassThrough) — never gated — so they are hidden entirely.
     * - The third-party LFM file manager (`uploads/*`) performs destructive operations over GET and is
     *   gated as ONE unit via viewWithoutToMessage() + the group wildcard ANY::<prefix>/uploads/* (the
     *   seeded `file.full`). Only that wildcard is exposed (individual sub-routes are noise), which keeps
     *   the seeded permission visible/editable. This restores v1 behaviour lost in the TailAdmin cutover.
     *
     * Display-only: this builder does not affect runtime enforcement (PermissionMiddleware/passRequest).
     *
     * @return array<string, array<int, array{uri:string, method:string, path:string}>>
     */
    public function getRouteGroupsProperty(): array
    {
        $prefix   = defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : '';
        $prefixed = static fn ($w) => ($prefix ? $prefix . '/' : '') . $w;

        $alwaysAllow  = array_map($prefixed, ['login', 'logout', 'forgot', 'deny', 'locale']);
        $wildcardOnly = array_map($prefixed, ['uploads']);

        $groups = [];
        foreach (Route::getRoutes() as $route) {
            if (! Str::startsWith($route->uri(), (string) $prefix)) {
                continue;
            }
            if (Str::startsWith($route->uri(), $alwaysAllow)) {
                continue;
            }

            $group = ltrim((string) $route->getPrefix(), '/') ?: $prefix;
            $groups[$group]['__wildcard'] = ['uri' => 'ANY::' . $group . '/*', 'method' => 'ANY', 'path' => $group . '/*'];

            // Gated-as-a-unit groups (LFM uploads) keep only the wildcard above; drop per-method entries.
            if (Str::startsWith($route->uri(), $wildcardOnly)) {
                continue;
            }

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
