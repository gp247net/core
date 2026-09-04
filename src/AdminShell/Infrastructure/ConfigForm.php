<?php

namespace GP247\Core\AdminShell\Infrastructure;

use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Abstract base for settings screens backed by the key/value admin_config table
 * (ADR-005). Rendered as a two-column "Setting | Value" table matching the legacy
 * admin look, with **live inline editing**: toggling a checkbox or editing a value
 * persists that single key immediately (no submit button) — each change is Layer-2
 * authorized (ADR-001) and gp247_clean'd.
 *
 * Reusable structure: a concrete screen declares group(), heading(), an optional
 * key subset (keys()) and per-key widget types (fieldTypes(): bool|number|text).
 *
 * **Per-store scope (opt-in, ADR plugin-manager_per-store-plugin-config).** A screen
 * that returns true from storeScoped() lets each store override the group's values on
 * top of the base (GLOBAL) rows, and toggle the plugin on/off per store. This only
 * activates when a multi-store/multi-vendor plugin is installed
 * (gp247_store_check_multi_domain_installed); otherwise every store-scope method is a
 * no-op and the screen behaves exactly as before (single-store parity). The base store
 * (storeId(), GLOBAL by default) always forms the key frame; a sub-store's rows are
 * lazily created on first override and cleared with resetToGlobal(). Secret keys
 * (fieldTypes()=password or admin_config.security=1) inherit at runtime but their
 * inherited value is NEVER rendered at a sub-store scope (NFR-SEC-plugin-secret-no-reveal).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005, US-AUI-config-form-store-scope
 * @aidlc-adr ADR-001, ADR-005, plugin-manager_per-store-plugin-config
 */
abstract class ConfigForm extends GP247AdminComponent
{
    use HasStoreScopeUi;

    /** @var array<string, mixed> Editable key => value map (booleans cast to bool). */
    public array $values = [];

    /** @var array<string, bool> key => whether the shown value is inherited from the base (GLOBAL) row. */
    public array $inherited = [];

    /** @var bool Effective on/off of the plugin at the selected sub-store (store-scope only). */
    public bool $storeEnabled = true;

    /**
     * The admin_config group this screen edits (e.g. "global"; "" for store group).
     *
     * @return string
     */
    abstract protected function group(): string;

    /**
     * Heading for the screen / first table column / layout title.
     *
     * @return string
     */
    abstract protected function heading(): string;

    /**
     * Base store scope for the config rows — the "shared/GLOBAL" tier that forms the
     * key frame and the inheritance fallback. Defaults to the global store; a variant
     * (e.g. StoreConfigForm) may override to ROOT.
     *
     * @return int|string
     */
    protected function storeId()
    {
        return defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
    }

    /**
     * Whether this screen supports per-store overrides. Concrete plugin screens
     * override to return true. Wired into HasStoreScopeUi via storeScopeOptIn().
     *
     * @return bool
     */
    protected function storeScoped(): bool
    {
        return false;
    }

    /**
     * HasStoreScopeUi opt-in: mirror storeScoped() so the shared store-scope helpers
     * (storeOptions/storeLabel/isRootScope/…) activate for this screen.
     *
     * @return bool
     */
    protected function storeScopeOptIn(): bool
    {
        return $this->storeScoped();
    }

    /**
     * The admin_config key that carries the plugin's on/off flag (group "Plugins"),
     * enabling the per-store enable toggle. Null (default) hides the toggle.
     *
     * @return string|null
     */
    protected function enableKey(): ?string
    {
        return null;
    }

    /**
     * The store the screen currently edits: the picked sub-store when store-scope is
     * active and a store is selected, otherwise the base store (storeId()).
     *
     * @return int|string
     */
    protected function scopeStoreId()
    {
        if ($this->storeScopeActive() && $this->formStoreId !== '') {
            return $this->formStoreId;
        }

        return $this->storeId();
    }

    /**
     * True when editing a sub-store (an overlay on top of the base rows), i.e. store
     * scope is active and the selected store differs from the base store.
     *
     * @return bool
     */
    protected function isSubStoreScope(): bool
    {
        return $this->storeScopeActive() && (string) $this->scopeStoreId() !== (string) $this->storeId();
    }

    /**
     * Optional whitelist of keys to expose. Empty = the whole group.
     *
     * @return array<int, string>
     */
    protected function keys(): array
    {
        return [];
    }

    /**
     * Per-key widget type: "bool" (checkbox), "number" (numeric input), "select"
     * (dropdown, see fieldOptions()), "password" or "text". Keys not listed default to "text".
     *
     * @return array<string, string>
     */
    protected function fieldTypes(): array
    {
        return [];
    }

    /**
     * Per-key option list for "select"-typed fields: key => [value => label, ...].
     *
     * @return array<string, array<int|string, string>>
     */
    protected function fieldOptions(): array
    {
        return [];
    }

    /**
     * @param string $key Config key.
     * @return string The widget type for the key.
     */
    public function typeOf(string $key): string
    {
        return $this->fieldTypes()[$key] ?? 'text';
    }

    /**
     * @param string $key Config key.
     * @return array<int|string, string> The select options for the key.
     */
    public function optionsOf(string $key): array
    {
        return $this->fieldOptions()[$key] ?? [];
    }

    /**
     * Per-key inline hint shown beside the field label — e.g. the currency-code unit.
     *
     * @return array<string, string>
     */
    protected function fieldHints(): array
    {
        return [];
    }

    /**
     * @param string $key Config key.
     * @return string The inline hint for the key, or '' when none.
     */
    public function hintOf(string $key): string
    {
        return (string) ($this->fieldHints()[$key] ?? '');
    }

    /**
     * Whether the key holds a boolean value (checkbox/toggle widgets bind a boolean).
     *
     * @param string $key Config key.
     * @return bool
     */
    private function isBooleanType(string $key): bool
    {
        return in_array($this->typeOf($key), ['bool', 'toggle'], true);
    }

    /**
     * Whether the key holds a secret (never rendered as inherited at a sub-store):
     * a password widget, or a base row flagged admin_config.security = 1.
     *
     * @param string $key Config key.
     * @param Collection<int, AdminConfig> $frame Base (GLOBAL) rows keyed by key.
     * @return bool
     */
    private function isSecretKey(string $key, Collection $frame): bool
    {
        if ($this->typeOf($key) === 'password') {
            return true;
        }
        $row = $frame->firstWhere('key', $key);

        return $row !== null && (int) $row->security === 1;
    }

    /**
     * Base-store rows for this group (the key frame + inheritance fallback), ordered.
     *
     * @return Collection<int, AdminConfig>
     */
    protected function configs(): Collection
    {
        return AdminConfig::where('group', $this->group())
            ->where('store_id', $this->storeId())
            ->when($this->keys() !== [], fn ($q) => $q->whereIn('key', $this->keys()))
            ->orderBy('sort')
            ->get();
    }

    /**
     * Sub-store override rows for this group, keyed by config key.
     *
     * @return Collection<string, AdminConfig>
     */
    private function overrideRows(): Collection
    {
        if (!$this->isSubStoreScope()) {
            return collect();
        }

        return AdminConfig::where('group', $this->group())
            ->where('store_id', $this->scopeStoreId())
            ->when($this->keys() !== [], fn ($q) => $q->whereIn('key', $this->keys()))
            ->get()
            ->keyBy('key');
    }

    /**
     * Livewire hook: authorize, seed the store scope, then load the effective values.
     *
     * @return void
     */
    public function mount(): void
    {
        parent::mount();

        if ($this->storeScopeActive()) {
            // Root admin starts at the shared (GLOBAL) scope and may pick a store; a
            // bound store-admin/vendor is locked to their own store (read-only picker).
            $this->formStoreId = $this->isRootScope() ? '' : (string) $this->storeContext();
        }

        $this->loadValues();
    }

    /**
     * Build the editable values + inheritance flags for the current scope, and the
     * per-store enable state. Secret keys inherited from the base are shown blank.
     *
     * @return void
     */
    private function loadValues(): void
    {
        $frame = $this->configs();
        $overrides = $this->overrideRows();
        $sub = $this->isSubStoreScope();

        $this->values = [];
        $this->inherited = [];

        foreach ($frame as $c) {
            $key = $c->key;
            $override = $sub ? $overrides->get($key) : null;
            $inherited = $sub && $override === null;
            $this->inherited[$key] = $inherited;

            if ($inherited && $this->isSecretKey($key, $frame)) {
                // WHY: never surface the shared secret at a sub-store scope
                // (NFR-SEC-plugin-secret-no-reveal). Empty = "using shared config".
                $this->values[$key] = $this->isBooleanType($key) ? false : '';
                continue;
            }

            $raw = $override !== null ? $override->value : $c->value;
            $this->values[$key] = $this->isBooleanType($key) ? (bool) (int) $raw : (string) $raw;
        }

        $this->storeEnabled = $this->resolveStoreEnabled();
    }

    /**
     * Effective on/off of the plugin at the selected sub-store: the sub-store's flag
     * row if present, else inherited from the GLOBAL flag (default on).
     *
     * @return bool
     */
    private function resolveStoreEnabled(): bool
    {
        if (!$this->showStoreEnableToggle()) {
            return true;
        }
        $global = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
        $row = AdminConfig::where('group', 'Plugins')
            ->where('key', $this->enableKey())
            ->where('store_id', $this->scopeStoreId())
            ->first();
        if ($row !== null) {
            return (bool) (int) $row->value;
        }
        $globalRow = AdminConfig::where('group', 'Plugins')
            ->where('key', $this->enableKey())
            ->where('store_id', $global)
            ->first();

        return $globalRow === null ? true : (bool) (int) $globalRow->value;
    }

    /**
     * Whether to render the per-store enable toggle (declared enableKey + editing a sub-store).
     *
     * @return bool
     */
    public function showStoreEnableToggle(): bool
    {
        return $this->enableKey() !== null && $this->isSubStoreScope();
    }

    /**
     * Whether the value cell for a key is currently an inherited (shared) value.
     *
     * @param string $key Config key.
     * @return bool
     */
    public function isInherited(string $key): bool
    {
        return (bool) ($this->inherited[$key] ?? false);
    }

    /**
     * Livewire hook: reload values when the root admin switches the scope picker
     * (no remount, so the table refreshes in place).
     *
     * @return void
     */
    public function updatedFormStoreId(): void
    {
        if ($this->formStoreId !== '' && !AdminStore::where('id', $this->formStoreId)->exists()) {
            // WHY: the store id comes from the client — never trust it; blank an invalid pick.
            $this->formStoreId = '';
        }
        $this->loadValues();
    }

    /**
     * Livewire hook: persist a single value the moment it changes (live editing). At a
     * sub-store scope this UPSERTs an override row (copying code/sort/detail from the
     * base row); at the base scope it updates the base row in place.
     *
     * @param mixed  $value The new value.
     * @param string $key   The changed config key (the `values.<key>` segment).
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function updatedValues($value, $key): void
    {
        $this->authorizeAction('update');

        $stored = $this->isBooleanType($key)
            ? ($value ? '1' : '0')
            : gp247_clean((string) $value);

        $isSecret = $this->isSecretKey($key, $this->configs());

        if ($this->isSubStoreScope()) {
            $base = AdminConfig::where('group', $this->group())
                ->where('key', $key)
                ->where('store_id', $this->storeId())
                ->first();
            $row = AdminConfig::firstOrNew([
                'group' => $this->group(),
                'key' => $key,
                'store_id' => $this->scopeStoreId(),
            ]);
            if ($base !== null) {
                $row->code = $base->code;
                $row->sort = $base->sort;
                $row->detail = $base->detail;
            }
            // WHY: force the secret flag so the model saving() hook encrypts at rest,
            // even if the base row was never flagged (ADR compat-foundation_config-secret-at-rest).
            $row->security = $isSecret ? 1 : (int) ($base->security ?? 0);
            // setAttribute bypasses the decrypt accessor; saving() encrypts if secret.
            $row->setAttribute('value', $stored);
            $row->save();
            $this->inherited[$key] = false;
        } else {
            // Base-scope write goes through the query builder, which bypasses the model
            // saving() hook — so encrypt the secret explicitly here and flag the row.
            $update = ['value' => $isSecret ? gp247_secret_encrypt($stored) : $stored];
            if ($isSecret) {
                $update['security'] = 1;
            }
            AdminConfig::where('key', $key)
                ->where('group', $this->group())
                ->where('store_id', $this->scopeStoreId())
                ->update($update);
        }

        $this->notify('success', gp247_language_render('admin.setting_saved'));
    }

    /**
     * Delete a sub-store override so the key falls back to the shared (GLOBAL) value.
     *
     * @param string $key Config key.
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function resetToGlobal(string $key): void
    {
        $this->authorizeAction('update');

        if (!$this->isSubStoreScope()) {
            return;
        }
        AdminConfig::where('group', $this->group())
            ->where('key', $key)
            ->where('store_id', $this->scopeStoreId())
            ->delete();

        $this->loadValues();
        $this->notify('success', gp247_language_render('admin.setting_saved'));
    }

    /**
     * Livewire hook: persist the plugin on/off flag for the selected sub-store the
     * moment the toggle changes (upsert the "Plugins" flag row).
     *
     * @param mixed $value The new toggle state.
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function updatedStoreEnabled($value): void
    {
        $this->authorizeAction('update');

        if (!$this->showStoreEnableToggle()) {
            return;
        }
        $global = AdminConfig::where('group', 'Plugins')
            ->where('key', $this->enableKey())
            ->where('store_id', defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0)
            ->first();
        $row = AdminConfig::firstOrNew([
            'group' => 'Plugins',
            'key' => $this->enableKey(),
            'store_id' => $this->scopeStoreId(),
        ]);
        if ($global !== null && !$row->exists) {
            $row->code = $global->code;
            $row->detail = $global->detail;
        }
        $row->value = $value ? '1' : '0';
        $row->save();

        $this->notify('success', gp247_language_render('admin.setting_saved'));
    }

    /**
     * @return View
     */
    public function render(): View
    {
        $configs = $this->configs();

        return view('gp247-admin::livewire.config-form', [
            'configs' => $configs,
            'heading' => $this->heading(),
            'types' => $configs->mapWithKeys(fn (AdminConfig $c) => [$c->key => $this->typeOf($c->key)])->all(),
            'options' => $configs->mapWithKeys(fn (AdminConfig $c) => [$c->key => $this->optionsOf($c->key)])->all(),
            'hints' => $configs->mapWithKeys(fn (AdminConfig $c) => [$c->key => $this->hintOf($c->key)])->all(),
            'storeScope' => $this->storeScopeActive(),
            'subStoreScope' => $this->isSubStoreScope(),
        ])->layout('gp247-admin::layouts.admin', ['title' => $this->heading()]);
    }
}
