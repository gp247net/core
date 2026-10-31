<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\Api\Support\ApiSwitch;
use GP247\Core\AdminShell\Infrastructure\HasValidationLabels;
use GP247\Core\Models\AdminApiConnection;
use GP247\Core\Models\AdminConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

/**
 * API connection screen (ADR-005) — the modern Livewire port of the legacy
 * AdminApiConnectionController two-column screen. The left column is a create/edit
 * form (description, connection, key + generate, expiry, status); the right column
 * holds the `api_connection_required` global toggle, the available API route list,
 * a curl usage hint and the table of existing connections.
 *
 * CRUD and the global toggle are Layer-2 authorized (ADR-001) and gp247_clean'd.
 * Gated by `admin_api_connection`. It also holds the REST API switches (master + v1/v2 ×
 * core/front, locked when GP247_API_MODE is off) and links the public API guide.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005, US-CMP-api-admin-switch, US-CMP-api-docs-link-from-admin
 * @aidlc-adr ADR-001, ADR-005
 */
class ApiConnectionManager extends GP247AdminComponent
{
    use WithPagination;
    use HasValidationLabels;

    /** The global config key toggling whether API calls must carry connection+key. */
    private const REQUIRED_KEY = 'api_connection_required';

    protected ?string $permission = 'admin_api_connection';

    /** @var int|null Id of the row being edited; null when creating. */
    public ?int $editingId = null;

    /** @var string Human-readable description. */
    public string $description = '';

    /** @var string Connection identifier (slug-like, unique). */
    public string $apiconnection = '';

    /** @var string New API key typed or generated in the form; never loaded back from the database. */
    public string $apikey = '';

    /**
     * Plain key of the connection just saved, rendered once in the save response
     * (US-CMP-api-connection-key-hashing). WHY protected: Livewire neither serialises
     * it into wire:snapshot nor sends it back, and the action log never sees it.
     *
     * @var string|null
     */
    protected ?string $revealedKey = null;

    /** @var string|null Expiry date (Y-m-d) or null for no expiry. */
    public ?string $expire = null;

    /** @var bool Whether the connection is enabled. */
    public bool $status = false;

    /** @var bool The api_connection_required global flag (right-column switch). */
    public bool $apiConnectionRequired = false;

    /**
     * REST API switches (ApiSwitch::KEYS => on). Locked: only setApiSwitch() changes them,
     * so the client cannot inject keys or non-boolean values through a property update.
     *
     * @var array<string, bool>
     */
    #[Locked]
    public array $apiSwitches = [];

    /** @var bool Whether GP247_API_MODE lets the API exist (false locks the switches). */
    #[Locked]
    public bool $apiServerEnabled = true;

    /**
     * Validation rules mirroring the legacy controller (connection slug unique,
     * key/connection restricted to url-safe chars).
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $ignore = $this->editingId ? (',' . $this->editingId . ',id') : '';

        return [
            'description' => 'required|string|max:500',
            'apiconnection' => 'required|string|max:50|regex:/^[0-9a-z\-_]+$/|unique:' . AdminApiConnection::class . ',apiconnection' . $ignore,
            // WHY min:32: the key is stored as an unsalted SHA-256, so it must carry enough entropy.
            'apikey' => 'nullable|string|min:32|max:128|regex:/^[0-9a-z\-_]+$/',
            'expire' => 'nullable|date',
        ];
    }

    /**
     * Reuse the existing v1 API-connection label keys for validator attributes.
     *
     * @return array<string, string>
     */
    protected function attributeLabels(): array
    {
        return [
            'description' => 'admin.api_connection.description',
            'apiconnection' => 'admin.api_connection.connection',
            'apikey' => 'admin.api_connection.apikey',
            'expire' => 'admin.api_connection.expire',
        ];
    }

    /**
     * Global store scope for the api_connection_required flag.
     *
     * @return int|string
     */
    private function globalStoreId()
    {
        return defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
    }

    /**
     * Livewire lifecycle hook: authorize the view and load the global flag.
     *
     * @return void
     */
    public function mount(): void
    {
        parent::mount();

        $this->apiConnectionRequired = (bool) (int) AdminConfig::where('key', self::REQUIRED_KEY)
            ->where('store_id', $this->globalStoreId())
            ->value('value');
        $this->apiSwitches = ApiSwitch::states();
        $this->apiServerEnabled = ApiSwitch::serverEnabled();
    }

    /**
     * Load a connection into the form for editing (read-only state change).
     *
     * @param int $id Connection id.
     * @return void
     */
    public function editRow(int $id): void
    {
        $row = AdminApiConnection::find($id);
        if ($row === null) {
            return;
        }

        $this->editingId = (int) $row->id;
        $this->description = (string) $row->description;
        $this->apiconnection = (string) $row->apiconnection;
        // WHY: keys are stored hashed and never shown again; empty = keep the current key.
        $this->apikey = '';
        $this->expire = $row->expire ? (string) $row->expire : null;
        $this->status = (bool) (int) $row->status;
        $this->resetErrorBag();
    }

    /**
     * Clear the form back to create mode.
     *
     * @return void
     */
    public function resetForm(): void
    {
        $this->editingId = null;
        $this->description = '';
        $this->apiconnection = '';
        $this->apikey = '';
        $this->expire = null;
        $this->status = false;
        $this->resetErrorBag();
    }

    /**
     * Fill the key field with a fresh random token (no persistence).
     *
     * @return void
     */
    public function generateKey(): void
    {
        $this->apikey = AdminApiConnection::newKey();
    }

    /**
     * Create or update the connection (Layer-2 gated).
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function save(): void
    {
        $this->authorizeAction('save');

        $validated = $this->validate();

        $payload = gp247_clean([
            'description' => $validated['description'],
            'apiconnection' => $validated['apiconnection'],
            'expire' => $validated['expire'] ?? null,
            'status' => $this->status ? 1 : 0,
        ], [], true);

        // A new connection always gets a key; an edited one keeps its key unless a new one was entered.
        $newKey = (string) ($validated['apikey'] ?? '');
        if ($newKey === '' && !$this->editingId) {
            $newKey = AdminApiConnection::newKey();
        }
        if ($newKey !== '') {
            $payload['apikey'] = $newKey;
        }

        if ($this->editingId) {
            // WHY model update (not query builder): the apikey mutator stores the hash only.
            AdminApiConnection::findOrFail($this->editingId)->update($payload);
        } else {
            AdminApiConnection::create($payload);
        }

        $this->resetForm();
        $this->revealedKey = $newKey !== '' ? $newKey : null;
        $this->resetPage();
        $this->notify('success', gp247_language_render('action.update_success'));
    }

    /**
     * Delete a connection (Layer-2 gated).
     *
     * @param int $id Connection id.
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function deleteRow(int $id): void
    {
        $this->authorizeAction('delete');

        AdminApiConnection::destroy($id);

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        $this->notify('success', gp247_language_render('action.delete_success'));
    }

    /**
     * Persist the api_connection_required toggle the moment it changes (Layer-2 gated).
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function updatedApiConnectionRequired(): void
    {
        $this->authorizeAction('update');

        AdminConfig::where('key', self::REQUIRED_KEY)
            ->where('store_id', $this->globalStoreId())
            ->update(['value' => $this->apiConnectionRequired ? '1' : '0']);

        $this->notify('success', gp247_language_render('admin.setting_saved'));
    }

    /**
     * Persist a REST API switch the moment its checkbox changes (Layer-2 gated, and a
     * `call` so the admin operation log records it). An unknown key is ignored; while the
     * server configuration (GP247_API_MODE) keeps the API off the change is refused.
     *
     * @param string $key One of ApiSwitch::KEYS.
     * @param bool   $on  New state of the switch.
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     *
     * @aidlc-story US-CMP-api-admin-switch
     */
    public function setApiSwitch(string $key, bool $on): void
    {
        $this->authorizeAction('update');

        if (!in_array($key, ApiSwitch::keys(), true)) {
            return;
        }
        if (!ApiSwitch::serverEnabled()) {
            $this->notify('error', gp247_language_render('admin.api_connection.switch_locked'));
            return;
        }

        AdminConfig::updateOrCreate(
            ['key' => $key, 'store_id' => $this->globalStoreId()],
            ['group' => 'global', 'code' => 'api_config', 'value' => $on ? '1' : '0']
        );
        $this->apiSwitches[$key] = $on;

        $this->notify('success', gp247_language_render('admin.setting_saved'));
    }

    /**
     * The API routes the switches control (those carrying the `api.enabled` gate),
     * grouped by the switch of their prefix; routes of a plugin with its own prefix go
     * to "other" (master switch only). A route with its own middleware chain (e.g. the
     * MCP server) is left out: these switches do not control it.
     *
     * @return array<string, array<int, string>> Switch key (or "other") => uris.
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-UI-005, US-CMP-api-v2-contract, US-CMP-api-admin-switch
     */
    private function apiRoutes(): array
    {
        $groups = array_fill_keys(array_merge(array_keys(ApiSwitch::prefixes()), ['other']), []);

        foreach (app('router')->getRoutes() as $route) {
            if (!in_array('api.enabled', $route->middleware(), true)) {
                continue;
            }
            $groups[ApiSwitch::groupOf($route->uri()) ?? 'other'][] = $route->uri();
        }

        return array_map(fn ($uris) => array_values(array_unique($uris)), $groups);
    }

    /**
     * Route boxes for the view: one per group switch (each box carries its own switch)
     * plus "other" (plugins with their own prefix, master switch only).
     *
     * @return array<int, array{key: string, switch: string|null, title: string, items: array<int, string>, on: bool}>
     */
    private function routeGroups(): array
    {
        $titles = [
            'api_v1_core'  => 'v1 · ' . gp247_language_render('admin.api_connection.switch_core'),
            'api_v1_front' => 'v1 · ' . gp247_language_render('admin.api_connection.switch_front'),
            'api_v2_core'  => 'v2 · ' . gp247_language_render('admin.api_connection.switch_core'),
            'api_v2_front' => 'v2 · ' . gp247_language_render('admin.api_connection.switch_front'),
            'other'        => gp247_language_render('admin.api_connection.group_other'),
        ];
        $master = $this->apiSwitches[ApiSwitch::MASTER] ?? true;
        $boxes = [];
        foreach ($this->apiRoutes() as $group => $uris) {
            $boxes[] = [
                // WHY str_replace: keeps the data-testid values of the v1/v2 boxes (api-connection-routes-v1-core…).
                'key'    => $group === 'other' ? 'other' : str_replace('_', '-', substr($group, 4)),
                'switch' => $group === 'other' ? null : $group,
                'title'  => $titles[$group],
                'items' => $uris,
                'on'    => $master && ($group === 'other' || ($this->apiSwitches[$group] ?? true)),
            ];
        }
        // Switches registered by packages (e.g. ordering through the API): own box, own toggle,
        // open only while the master and the parent group are open.
        foreach (ApiSwitch::registered() as $key => $switch) {
            $uris = array_values(array_filter(array_merge(...array_values($this->apiRoutes())),
                fn ($uri) => $uri === $switch['prefix'] || str_starts_with($uri, $switch['prefix'] . '/')));
            $boxes[] = [
                'key'    => str_replace('_', '-', substr($key, 4)),
                'switch' => $key,
                'title'  => gp247_language_render($switch['label']),
                'items'  => $uris,
                'on'     => $master && ($this->apiSwitches[$switch['parent']] ?? true) && ($this->apiSwitches[$key] ?? $switch['default']),
            ];
        }
        return $boxes;
    }

    /**
     * Public API guide in the admin's language (English when missing).
     *
     * @return string|null
     *
     * @aidlc-story US-CMP-api-docs-link-from-admin
     */
    private function docsGuide(): ?string
    {
        $guides = (array) config('gp247-config.api.docs.guide', []);

        return $guides[app()->getLocale()] ?? ($guides['en'] ?? null);
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('gp247-admin::livewire.api-connection-manager', [
            'rows' => AdminApiConnection::orderBy('id', 'desc')->paginate(10),
            'routeGroups' => $this->routeGroups(),
            'docsGuide' => $this->docsGuide(),
            'revealedKey' => $this->revealedKey,
        ])->layout('gp247-admin::layouts.admin', ['title' => gp247_language_render('admin.api_connection.list')]);
    }
}
