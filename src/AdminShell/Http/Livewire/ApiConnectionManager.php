<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\Models\AdminApiConnection;
use GP247\Core\Models\AdminConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\WithPagination;

/**
 * API connection screen (ADR-005) — the modern Livewire port of the legacy
 * AdminApiConnectionController two-column screen. The left column is a create/edit
 * form (description, connection, key + generate, expiry, status); the right column
 * holds the `api_connection_required` global toggle, the available API route list,
 * a curl usage hint and the table of existing connections.
 *
 * CRUD and the global toggle are Layer-2 authorized (ADR-001) and gp247_clean'd.
 * Gated by `admin_api_connection`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-001, ADR-005
 */
class ApiConnectionManager extends GP247AdminComponent
{
    use WithPagination;

    /** The global config key toggling whether API calls must carry connection+key. */
    private const REQUIRED_KEY = 'api_connection_required';

    protected ?string $permission = 'admin_api_connection';

    /** @var int|null Id of the row being edited; null when creating. */
    public ?int $editingId = null;

    /** @var string Human-readable description. */
    public string $description = '';

    /** @var string Connection identifier (slug-like, unique). */
    public string $apiconnection = '';

    /** @var string API key/token. */
    public string $apikey = '';

    /** @var string|null Expiry date (Y-m-d) or null for no expiry. */
    public ?string $expire = null;

    /** @var bool Whether the connection is enabled. */
    public bool $status = false;

    /** @var bool The api_connection_required global flag (right-column switch). */
    public bool $apiConnectionRequired = false;

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
            'apikey' => 'nullable|string|max:128|regex:/^[0-9a-z\-_]+$/',
            'expire' => 'nullable|date',
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
        $this->apikey = (string) $row->apikey;
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
        $this->apikey = md5(Str::random(40));
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
            'apikey' => $validated['apikey'] ?? '',
            'expire' => $validated['expire'] ?? null,
            'status' => $this->status ? 1 : 0,
        ], [], true);

        if ($this->editingId) {
            AdminApiConnection::where('id', $this->editingId)->update($payload);
        } else {
            AdminApiConnection::create($payload);
        }

        $this->resetForm();
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
     * The registered API routes, split into core and front by their url prefix.
     *
     * @return array{core: array<int, string>, front: array<int, string>}
     */
    private function apiRoutes(): array
    {
        $core = [];
        $front = [];
        $corePrefix = defined('GP247_API_CORE_PREFIX') ? GP247_API_CORE_PREFIX : 'api/v1';
        $frontPrefix = defined('GP247_API_FRONT_PREFIX') ? GP247_API_FRONT_PREFIX : 'api';

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if (Str::startsWith($uri, $corePrefix)) {
                $core[] = $uri;
            } elseif (Str::startsWith($uri, $frontPrefix)) {
                $front[] = $uri;
            }
        }

        return ['core' => array_values(array_unique($core)), 'front' => array_values(array_unique($front))];
    }

    /**
     * @return View
     */
    public function render(): View
    {
        $routes = $this->apiRoutes();

        return view('gp247-admin::livewire.api-connection-manager', [
            'rows' => AdminApiConnection::orderBy('id', 'desc')->paginate(10),
            'listCore' => $routes['core'],
            'listFront' => $routes['front'],
        ])->layout('gp247-admin::layouts.admin', ['title' => gp247_language_render('admin.api_connection.list')]);
    }
}
