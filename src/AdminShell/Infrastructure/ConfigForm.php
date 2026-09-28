<?php

namespace GP247\Core\AdminShell\Infrastructure;

use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

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

    /**
     * When set (by a parent screen embedding this form, e.g. StoreConfigManager at
     * gp247_admin/MultiStore/config/{store}), the form edits THIS store's config
     * regardless of the session/picker — the caller owns "which store". #[Locked] so
     * the client cannot forge it. Empty/null → the standalone behaviour: ROOT for the
     * root admin (no picker), the bound store for a store-admin (mod 20260906T000000).
     *
     * @var string|null
     * @aidlc-story US-AUI-core-config-store-scope
     * @aidlc-adr admin-shell_core-config-store-scope
     */
    #[Locked]
    public ?string $scopeStoreIdOverride = null;

    /** @var array<string, mixed> Editable key => value map (booleans cast to bool). */
    public array $values = [];

    /** @var array<string, bool> key => whether the shown value is inherited from the base (GLOBAL) row. */
    public array $inherited = [];

    /**
     * Snapshot of the loaded values (secrets excluded) used by save() to persist only the
     * keys the admin actually changed — so a sub-store Save never materialises overrides for
     * untouched, still-inherited keys.
     *
     * @var array<string, mixed>
     */
    public array $original = [];

    /** @var bool Effective on/off of the plugin at the selected sub-store (store-scope only). */
    public bool $storeEnabled = true;

    /**
     * Secret key => whether a value is stored for it at the current scope (own row, or the
     * inherited base row). The value itself is never loaded into this public, browser-
     * serialized component: secrets are write-only on this screen
     * (NFR-SEC-config-secret-write-only-ui).
     *
     * @var array<string, bool>
     * @aidlc-story US-AUI-config-form-secret-write-only
     */
    public array $secretSet = [];

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
        // Embedded (StoreConfigManager passes the store id): that store wins over the
        // session/picker — the host owns "which store" (mod 20260906T000000).
        if ($this->scopeStoreIdOverride !== null && $this->scopeStoreIdOverride !== '') {
            return $this->scopeStoreIdOverride;
        }

        // Standalone (store_config): no picker anymore — formStoreId is only set for a
        // bound store-admin (mount()); the root admin resolves to the base store (ROOT).
        if ($this->storeScopeActive() && $this->formStoreId !== '') {
            return $this->formStoreId;
        }

        return $this->storeId();
    }

    /**
     * No store picker on the config screens (mod 20260906T000000): the root admin edits
     * ROOT here, a store-admin their bound store, and per-store config for the root admin
     * lives at gp247_admin/MultiStore/config/{store} (which embeds this form with an
     * override). Inheritance/override badges still render (they key off isSubStoreScope()).
     *
     * @return bool
     */
    public function storeScopeUiVisible(): bool
    {
        return false;
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
     * Optional grouping of the keys into titled blocks, in display order.
     *
     * Each entry: `id` (slug, used in data-testid), `title`, optional `hint` (one line
     * under the title — the place for instructions that apply to the whole block instead of
     * repeating them on every field) and optional `badge` (short label such as "In use"),
     * plus the `keys` it holds in the order to show them. The block order wins over the
     * rows' `sort`. Keys no block claims are listed after the blocks; a block whose keys
     * are all absent is skipped. Empty (default) = one flat table, exactly as before.
     *
     * WHY a seam here: a plugin with two parallel key sets (sandbox / live credentials) must
     * not leave the admin guessing which field belongs to which environment, and every
     * payment plugin has the same need (ui-tailadmin.md P3).
     *
     * @return array<int, array{id: string, title: string, hint?: string, badge?: string, keys: array<int, string>}>
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-AUI-config-form-sections
     */
    protected function sections(): array
    {
        return [];
    }

    /**
     * Flatten the rows to render: section headings interleaved with config rows.
     *
     * @param Collection<int, AdminConfig> $configs Base rows, sorted.
     * @return array<int, array<string, mixed>> Items of either
     *         ['section' => true, 'id', 'title', 'hint', 'badge'] or ['config' => AdminConfig].
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-AUI-config-form-sections
     */
    private function layoutRows(Collection $configs): array
    {
        $sections = $this->sections();
        if ($sections === []) {
            return $configs->map(fn (AdminConfig $c) => ['config' => $c])->values()->all();
        }

        $byKey = $configs->keyBy('key');
        $rows = [];
        $claimed = [];
        foreach ($sections as $section) {
            $present = array_values(array_filter(
                (array) ($section['keys'] ?? []),
                fn ($key) => $byKey->has($key) && !isset($claimed[$key])
            ));
            if ($present === []) {
                continue;
            }
            $rows[] = [
                'section' => true,
                'id' => (string) preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) ($section['id'] ?? ''))),
                'title' => (string) ($section['title'] ?? ''),
                'hint' => (string) ($section['hint'] ?? ''),
                'badge' => (string) ($section['badge'] ?? ''),
            ];
            foreach ($present as $key) {
                $claimed[$key] = true;
                $rows[] = ['config' => $byKey->get($key)];
            }
        }
        foreach ($configs as $c) {
            if (!isset($claimed[$c->key])) {
                $rows[] = ['config' => $c];
            }
        }

        return $rows;
    }

    /**
     * Keys that are shown but cannot be edited on this screen, with the reason.
     *
     * A locked key still renders — so the admin learns the setting exists — but
     * its input is disabled, a lock icon and `hint` explain why, and an optional
     * `url`/`label` pair offers the way to unlock it (a licence, an add-on, a
     * paid edition). save() never writes a locked key, so a stale value in the
     * buffer cannot slip through.
     *
     * WHY a seam rather than hiding the key: hiding is the one thing a plugin
     * must not do to a setting it wants the admin to want.
     *
     * The optional `value` names the state that is ACTUALLY IN EFFECT while the
     * key stays locked, and the screen displays that instead of the stored one.
     * Without it a downgraded site shows whatever the paid edition last saved —
     * a ticked switch for a feature its gate is blocking — so the screen claims
     * the admin already has what the lock is offering to sell. The owner of the
     * feature must name it, because only they know where a gated feature lands:
     * `false` for a switch, but e.g. the "shipping" preset for a choice whose
     * configured default is "confirm". It is display only: save() still skips
     * locked keys, so the stored value survives and comes back on unlock.
     *
     * @return array<string, array{hint: string, url?: string|null, label?: string|null, value?: mixed}>
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-UI-005
     */
    protected function lockedKeys(): array
    {
        return [];
    }

    /**
     * @param string $key Config key.
     * @return array{hint: string, url?: string|null, label?: string|null}|null The lock, or null when editable.
     */
    public function lockOf(string $key): ?array
    {
        $lock = $this->lockedKeys()[$key] ?? null;

        return is_array($lock) ? $lock : null;
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
        // (string) cast: store_id is char(36); an int bind coerces the column to DOUBLE
        // (ADR compat-foundation_store-id-string-identity).
        return AdminConfig::where('group', $this->group())
            ->where('store_id', (string) $this->storeId())
            ->when($this->keys() !== [], fn ($q) => $q->whereIn('key', $this->keys()))
            ->orderBy('sort')
            // WHY the tie-break: plugins often seed every row at sort 0, and without it
            // MySQL/MariaDB return them in whatever order the chosen index yields — the same
            // screen showed different field orders on two sites. id is auto-increment, so
            // ties keep the order the rows were seeded in.
            ->orderBy('id')
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
            ->where('store_id', (string) $this->scopeStoreId())
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
        $this->secretSet = [];

        foreach ($frame as $c) {
            $key = $c->key;
            $override = $sub ? $overrides->get($key) : null;
            $inherited = $sub && $override === null;
            $this->inherited[$key] = $inherited;

            if ($this->isSecretKey($key, $frame) && !$this->isBooleanType($key)) {
                // WHY: public properties are serialized into wire:snapshot, so a decrypted
                // secret loaded here would reach the browser at ANY scope — not only when
                // inherited. Load it blank and expose only whether one is stored; reading
                // the raw column avoids decrypting at all (NFR-SEC-config-secret-write-only-ui).
                $source = $override ?? $c;
                $this->secretSet[$key] = (string) $source->getRawOriginal('value') !== '';
                $this->values[$key] = '';
                continue;
            }

            if ($inherited && $this->isSecretKey($key, $frame)) {
                // WHY: never surface the shared secret at a sub-store scope
                // (NFR-SEC-plugin-secret-no-reveal). Empty = "using shared config".
                $this->values[$key] = $this->isBooleanType($key) ? false : '';
                continue;
            }

            $lock = $this->lockOf($key);
            if ($lock !== null && array_key_exists('value', $lock)) {
                // Locked with a declared effective state: show what is running,
                // not what is stored (see lockedKeys()).
                $this->values[$key] = $this->isBooleanType($key) ? (bool) $lock['value'] : (string) $lock['value'];
                continue;
            }

            $raw = $override !== null ? $override->value : $c->value;
            $this->values[$key] = $this->isBooleanType($key) ? (bool) (int) $raw : (string) $raw;
        }

        // Snapshot for save()'s dirty check; exclude secrets (never keep a real secret in a
        // public, browser-serialized property — they are handled by the blank-skip rule).
        $this->original = [];
        foreach ($this->values as $k => $v) {
            $this->original[$k] = $this->isSecretKey($k, $frame) ? null : $v;
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

        // Shared read semantics with the "Manage Plugin" list toggle so the two enable
        // channels never drift (NFR-MAINT-store-scope-single-mechanism).
        return gp247_plugin_store_enabled($this->enableKey(), $this->scopeStoreId());
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
     * Label for the scope picker's first item — the base (shared) scope. Defaults to
     * the generic "shared/global" wording used by plugin config screens whose base is
     * the virtual GLOBAL tier; a variant whose base is a real store (StoreConfigForm,
     * base = ROOT) overrides this so the option reads as that root store rather than a
     * "global" tier that does not exist for core config.
     *
     * @return string
     */
    public function scopeBaseLabel(): string
    {
        return gp247_language_quickly('admin.store.scope_global', 'Default config');
    }

    /**
     * Store options for the scope picker, excluding the base store itself: the base is
     * already the picker's first ("shared") item, so listing it again as a selectable
     * sub-store would create two entries resolving to the same rows. For plugin config
     * (base = virtual GLOBAL, never an AdminStore row) this removes nothing; for
     * StoreConfigForm (base = ROOT, a real store) it drops the duplicate ROOT entry.
     *
     * @return array<int|string, string>
     */
    public function storePickerOptions(): array
    {
        $base = (string) $this->storeId();

        return array_filter(
            $this->storeOptions(),
            fn ($sid) => (string) $sid !== $base,
            ARRAY_FILTER_USE_KEY,
        );
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
    /**
     * Persist every editable key at once (explicit Save — no live per-field write, so a
     * mis-typed field or a stray dropdown change never reaches the DB until the admin
     * commits; ADR-005 amended 2026-09-05). The store picker, the per-store enable toggle
     * and "use shared config" stay immediate (deliberate single controls).
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    /**
     * Hook: normalise/clamp a submitted value before persistence. Override in a subclass
     * (e.g. CacheConfigForm clamps cache_time to a sane minimum). Default: unchanged.
     *
     * @param string $key   Config key.
     * @param mixed  $value  Submitted value.
     * @return mixed Normalised value.
     */
    protected function normalizeValue(string $key, $value)
    {
        return $value;
    }

    public function save(): void
    {
        $this->authorizeAction('update');

        foreach ($this->keys() as $key) {
            // A locked key is displayed, never written — whatever the buffer holds.
            if ($this->lockOf($key) !== null) {
                continue;
            }
            $this->persistValue($key, $this->values[$key] ?? null);
        }

        $this->clearSecretBuffer();

        $this->notify('success', gp247_language_render('admin.setting_saved'));
    }

    /**
     * After a save, forget every secret the admin typed so the Livewire response does not
     * carry it back to the browser; a non-blank entry means one is now stored.
     *
     * @return void
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-AUI-config-form-secret-write-only
     */
    private function clearSecretBuffer(): void
    {
        $frame = $this->configs();
        // Walk the loaded values, not keys(): an empty keys() means "the whole group".
        foreach (array_keys($this->values) as $key) {
            if (!$this->isSecretKey($key, $frame) || $this->isBooleanType($key)) {
                continue;
            }
            if ($this->lockOf($key) === null && (string) ($this->values[$key] ?? '') !== '') {
                $this->secretSet[$key] = true;
            }
            $this->values[$key] = '';
        }
    }

    /**
     * Whether a secret key currently has a stored value (for the "saved / not set" label).
     *
     * @param string $key Config key.
     * @return bool|null Null when the key is not a secret on this screen.
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-AUI-config-form-secret-write-only
     */
    public function secretStateOf(string $key): ?bool
    {
        return array_key_exists($key, $this->secretSet) ? (bool) $this->secretSet[$key] : null;
    }

    /**
     * Persist one key to the effective scope (base GLOBAL or the selected sub-store),
     * honouring at-rest secret encryption and the write-only rule. Not a Livewire hook —
     * called only by save() so nothing is written implicitly on field change.
     *
     * @param string $key   Config key.
     * @param mixed  $value  Submitted value.
     * @return void
     */
    private function persistValue(string $key, $value): void
    {
        // Let a subclass clamp/normalise a value before it is stored (e.g. CacheConfigForm
        // forcing a minimum cache_time). Default: unchanged.
        $value = $this->normalizeValue($key, $value);

        $isSecret = $this->isSecretKey($key, $this->configs());

        // Write-only secret: a blank secret field means "keep the stored one" — never
        // overwrite an existing secret (or create an empty sub-store override) with '',
        // which is what an untouched password field submits (NFR-SEC-mail-secret-display,
        // mirrors LoginSocial::save()).
        if ($isSecret && !$this->isBooleanType($key) && (string) $value === '') {
            return;
        }

        // At a sub-store scope, only write keys the admin actually changed, so untouched
        // (still-inherited) keys are NOT materialised into overrides on Save. Secrets skip
        // this check: a non-blank secret is always an intentional new value. Base scope
        // has no inheritance, so it persists the whole form.
        if ($this->isSubStoreScope() && !$isSecret) {
            $isBool = $this->isBooleanType($key);
            $norm = fn ($v) => $isBool ? ($v ? '1' : '0') : (string) $v;
            if ($norm($value) === $norm($this->original[$key] ?? null)) {
                return;
            }
        }

        $stored = $this->isBooleanType($key)
            ? ($value ? '1' : '0')
            : gp247_clean((string) $value);

        if ($this->isSubStoreScope()) {
            $base = AdminConfig::where('group', $this->group())
                ->where('key', $key)
                ->where('store_id', (string) $this->storeId())
                ->first();
            $row = AdminConfig::firstOrNew([
                'group' => $this->group(),
                'key' => $key,
                'store_id' => (string) $this->scopeStoreId(),
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
                ->where('store_id', (string) $this->scopeStoreId())
                ->update($update);
        }
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
            ->where('store_id', (string) $this->scopeStoreId())
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

        // Shared write semantics with the "Manage Plugin" list toggle (one truth, two
        // writers — NFR-MAINT-store-scope-single-mechanism).
        gp247_plugin_store_enable_set($this->enableKey(), $this->scopeStoreId(), (bool) $value);

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
            // A row flagged security = 1 is drawn as a password input even when the screen
            // declared it as text, so a newly typed secret is not shown while typing.
            'types' => $configs->mapWithKeys(fn (AdminConfig $c) => [
                $c->key => ($this->isSecretKey($c->key, $configs) && !$this->isBooleanType($c->key)) ? 'password' : $this->typeOf($c->key),
            ])->all(),
            'options' => $configs->mapWithKeys(fn (AdminConfig $c) => [$c->key => $this->optionsOf($c->key)])->all(),
            'hints' => $configs->mapWithKeys(fn (AdminConfig $c) => [$c->key => $this->hintOf($c->key)])->all(),
            'locked' => $configs->mapWithKeys(fn (AdminConfig $c) => [$c->key => $this->lockOf($c->key)])->all(),
            // Chrome (picker + per-key badges) shows only for the ROOT admin; a bound
            // store-admin edits their store's config with no store-scope chrome
            // (US-admin-shell-store-scope-ui-root-only). Data scope is unchanged.
            'rows' => $this->layoutRows($configs),
            'storeScope' => $this->storeScopeUiVisible(),
            'subStoreScope' => $this->isSubStoreScope() && $this->isRootScope(),
        ])->layout('gp247-admin::layouts.admin', ['title' => $this->heading()]);
    }
}
