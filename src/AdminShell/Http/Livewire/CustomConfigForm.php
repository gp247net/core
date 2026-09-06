<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\AdminShell\Infrastructure\HasStoreScopeUi;
use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

/**
 * Custom config screen (admin_custom_config group) — the modern Livewire port of
 * the legacy "Cấu hình tùy chỉnh" tab (AdminStoreConfigController). A free-form
 * key/value editor: it lists every admin_custom_config row (the seeded social links
 * are just defaults among them), supports adding a new config (detail/key/value),
 * deleting a row and editing values live inline.
 *
 * **Per-store (independent, NOT inherited) — US-AUI-core-config-store-scope.** Unlike
 * StoreConfigForm's fixed-key screens (which inherit ROOT and override), this is a
 * free-form add/delete editor, so each store keeps its OWN independent set of rows
 * with no inheritance: a root admin picks a store (defaults to ROOT), a bound
 * store-admin is locked to their own store, and every query/mutation is scoped to
 * that effective store. Only activates when a multi-store/multi-vendor plugin is
 * installed (gp247_store_check_multi_domain_installed) — otherwise it edits ROOT
 * exactly as before (single-store parity).
 *
 * Each mutation is Layer-2 authorized (ADR-001) and gp247_clean'd; keys are unique
 * per store. Gated by `admin_config`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005, US-AUI-core-config-store-scope
 * @aidlc-adr ADR-001, ADR-005, admin-shell_core-config-store-scope
 */
class CustomConfigForm extends GP247AdminComponent
{
    use HasStoreScopeUi;

    /**
     * When set by an embedding host (StoreConfigManager at MultiStore/config/{store}),
     * edit THIS store's custom config regardless of session/picker (mod 20260906T000000).
     * #[Locked] so the client cannot forge it.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $scopeStoreIdOverride = null;

    /** The admin_config "code" grouping free-form custom configs. */
    private const CODE = 'admin_custom_config';

    protected ?string $permission = 'admin_config';

    /**
     * Opt into store scoping (guarded at runtime by gp247_store_check_multi_domain_installed()).
     *
     * @return bool
     */
    protected function storeScopeOptIn(): bool
    {
        return true;
    }

    /** @var array<string, string> Editable key => value map for inline live editing. */
    public array $values = [];

    /** @var string New config human-readable detail/label (add row). */
    public string $newDetail = '';

    /** @var string New config key (add row). */
    public string $newKey = '';

    /** @var string New config value (add row). */
    public string $newValue = '';

    /**
     * The ROOT store — the default scope and the single-store fallback.
     *
     * @return int|string
     */
    private function storeId()
    {
        return defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1;
    }

    /**
     * The store this screen currently manages (independent per-store, no inheritance):
     * when store scope is active, the root admin's picked store (formStoreId, default
     * ROOT) or a bound store-admin's own store; otherwise always ROOT (parity).
     *
     * @return int|string
     */
    private function effectiveStore()
    {
        // Embedded host owns "which store" (mod 20260906T000000).
        if ($this->scopeStoreIdOverride !== null && $this->scopeStoreIdOverride !== '') {
            return $this->scopeStoreIdOverride;
        }

        if (!$this->storeScopeActive()) {
            return $this->storeId();
        }

        // No picker anymore: root → ROOT, a bound store-admin → their own store.
        if ($this->isRootScope()) {
            return $this->storeId();
        }

        return $this->storeContext();
    }

    /**
     * No store picker on the custom-config screen (mod 20260906T000000) — per-store is
     * chosen via the store's own MultiStore/config route (embeds this form with override).
     *
     * @return bool
     */
    public function storeScopeUiVisible(): bool
    {
        return false;
    }

    /**
     * Load the custom-config rows for the effective store, ordered by sort then key.
     *
     * @return Collection<int, AdminConfig>
     */
    private function rows(): Collection
    {
        // (string) cast: store_id is char(36); an int bind coerces the column to DOUBLE
        // (ADR compat-foundation_store-id-string-identity).
        return AdminConfig::where('code', self::CODE)
            ->where('store_id', (string) $this->effectiveStore())
            ->orderBy('sort')
            ->orderBy('key')
            ->get();
    }

    /**
     * Seed the editable key => value map from the current rows.
     *
     * @return void
     */
    private function syncValues(): void
    {
        $this->values = $this->rows()
            ->mapWithKeys(fn (AdminConfig $c) => [$c->key => (string) $c->value])
            ->all();
    }

    /**
     * Livewire lifecycle hook: authorize the view and load the rows.
     *
     * @return void
     */
    public function mount(): void
    {
        parent::mount();

        if ($this->storeScopeActive()) {
            // Root admin starts at ROOT (its own custom configs) and may pick another
            // store; a bound store-admin is locked to their own store (read-only picker).
            $this->formStoreId = $this->isRootScope()
                ? (string) $this->storeId()
                : (string) $this->storeContext();
        }

        $this->syncValues();
    }

    /**
     * Livewire hook: reload the rows when the root admin switches store (no remount).
     * The picked store comes from the client, so validate it before trusting it.
     *
     * @return void
     */
    public function updatedFormStoreId(): void
    {
        if ($this->formStoreId === '' || !AdminStore::where('id', $this->formStoreId)->exists()) {
            $this->formStoreId = (string) $this->storeId();
        }

        $this->syncValues();
    }

    /**
     * Persist a single value the moment it changes (live editing, Layer-2 gated).
     *
     * @param mixed  $value The new value.
     * @param string $key   The changed config key (the `values.<key>` segment).
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function updatedValues($value, string $key): void
    {
        $this->authorizeAction('update');

        AdminConfig::where('key', $key)
            ->where('code', self::CODE)
            ->where('store_id', (string) $this->effectiveStore())
            ->update(['value' => gp247_clean((string) $value)]);

        $this->notify('success', gp247_language_render('admin.setting_saved'));
    }

    /**
     * Add a new custom config (Layer-2 gated). The key is required and unique per
     * store; duplicates are rejected like the legacy controller.
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function addNew(): void
    {
        $this->authorizeAction('create');

        $key = trim($this->newKey);
        if ($key === '') {
            $this->notify('error', gp247_language_render('admin.not_empty'));

            return;
        }

        $exists = AdminConfig::where('key', $key)
            ->where('store_id', (string) $this->effectiveStore())
            ->exists();
        if ($exists) {
            $this->notify('error', gp247_language_quickly('admin.admin_custom_config.key_exist', 'Key already exist'));

            return;
        }

        AdminConfig::insert([
            'key' => $key,
            'value' => gp247_clean($this->newValue),
            'detail' => gp247_clean($this->newDetail),
            'code' => self::CODE,
            'group' => '',
            'store_id' => (string) $this->effectiveStore(),
            'sort' => 0,
        ]);

        $this->newDetail = '';
        $this->newKey = '';
        $this->newValue = '';
        $this->syncValues();

        $this->notify('success', gp247_language_render('action.update_success'));
    }

    /**
     * Delete a custom config by key (Layer-2 gated).
     *
     * @param string $key The config key to remove.
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function deleteKey(string $key): void
    {
        $this->authorizeAction('delete');

        AdminConfig::where('key', $key)
            ->where('code', self::CODE)
            ->where('store_id', (string) $this->effectiveStore())
            ->delete();

        unset($this->values[$key]);
        $this->syncValues();

        $this->notify('success', gp247_language_render('action.delete_success'));
    }

    /**
     * @return View
     */
    public function render(): View
    {
        $heading = gp247_language_render('admin.cfg_custom');

        return view('gp247-admin::livewire.custom-config-form', [
            'rows' => $this->rows(),
            'heading' => $heading,
            // Store picker shows only for the ROOT admin; a bound store-admin manages
            // their own store's custom configs with no picker (data scope unchanged —
            // US-admin-shell-store-scope-ui-root-only).
            'storeScope' => $this->storeScopeUiVisible(),
        ])->layout('gp247-admin::layouts.admin', ['title' => $heading]);
    }
}
