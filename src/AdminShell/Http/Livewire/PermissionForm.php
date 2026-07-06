<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\FormComponent;
use GP247\Core\Models\AdminPermission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Create/edit form for an RBAC permission (ADR-001/005). The `http_uri` is picked
 * from the live admin route list (grouped by prefix) and stored comma-joined —
 * matching the brownfield permission editor. Gated by `admin_permission`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005
 */
class PermissionForm extends FormComponent
{
    protected ?string $permission = 'admin_permission';

    /** @var array<string, mixed> name, slug, and http_uri (array of selected URIs). */
    public array $form = ['name' => '', 'slug' => '', 'http_uri' => []];

    /** @var string Client-side filter for the route picker. */
    public string $routeFilter = '';

    /**
     * @param string|null $id Permission id to edit; null to create.
     * @return void
     */
    public function mount(?string $id = null): void
    {
        parent::mount();

        if ($id !== null) {
            $permission = AdminPermission::findOrFail($id);
            $this->editingId = (string) $permission->id;
            $this->form = [
                'name' => $permission->name,
                'slug' => $permission->slug,
                'http_uri' => $permission->http_uri ? explode(',', $permission->http_uri) : [],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $table = (new AdminPermission())->getTable();

        return [
            'form.name' => ['required', 'string', 'max:50', Rule::unique($table, 'name')->ignore($this->editingId)],
            'form.slug' => ['required', 'string', 'min:3', 'max:50', 'regex:/^([0-9A-Za-z._\-]+)$/', Rule::unique($table, 'slug')->ignore($this->editingId)],
            'form.http_uri' => ['array'],
            'form.http_uri.*' => ['string'],
        ];
    }

    /**
     * @return array<string, string> Validation messages.
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
     * Save, then return to the list with a flash.
     *
     * @return void
     */
    public function save(): void
    {
        parent::save();

        session()->flash('gp247_admin_success', gp247_language_render('admin.core.save_success'));
        $this->redirectRoute('admin_permission.index', navigate: true);
    }

    /**
     * Build the admin route list grouped by prefix for the URI picker. Mirrors the
     * brownfield permission editor: each group offers an ANY::prefix/* wildcard plus
     * the concrete METHOD::uri routes (auth/util routes excluded).
     *
     * @return array<string, array<int, array{uri:string, method:string, path:string}>>
     */
    public function getRouteGroupsProperty(): array
    {
        $prefix = defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : '';
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
                    'uri' => $method . '::' . $route->uri(),
                    'method' => $method,
                    'path' => $route->uri(),
                ];
            }
        }

        ksort($groups);

        return array_map('array_values', $groups);
    }

    /**
     * @return array{name: string, url: string}
     */
    protected function listCrumb(): array
    {
        return ['name' => gp247_language_render('admin.permission.title'), 'url' => route('admin_permission.index')];
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('gp247-admin::livewire.permission-form', [
            'routeGroups' => $this->routeGroups,
        ])->layout('gp247-admin::layouts.admin', [
            'title' => $this->editingId !== null ? 'Edit Permission' : 'New Permission',
            'breadcrumb' => $this->listCrumb(),
        ]);
    }
}
