<?php

namespace GP247\Core\AdminShell\Infrastructure;

use GP247\Core\Models\AdminConfig;
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
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-001, ADR-005
 */
abstract class ConfigForm extends GP247AdminComponent
{
    /** @var array<string, mixed> Editable key => value map (booleans cast to bool). */
    public array $values = [];

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
     * Store scope for the config rows. Defaults to the global store.
     *
     * @return int|string
     */
    protected function storeId()
    {
        return defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
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
     * (dropdown, see fieldOptions()) or "text". Keys not listed default to "text".
     *
     * @return array<string, string>
     */
    protected function fieldTypes(): array
    {
        return [];
    }

    /**
     * Per-key option list for "select"-typed fields: key => [value => label, ...].
     * Keys not listed (or non-"select" keys) render an empty option list.
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
     * Whether the key holds a boolean value. Both the "bool" (checkbox) and
     * "toggle" (on/off switch) widgets bind a boolean, so they cast/persist alike.
     *
     * @param string $key Config key.
     * @return bool
     */
    private function isBooleanType(string $key): bool
    {
        return in_array($this->typeOf($key), ['bool', 'toggle'], true);
    }

    /**
     * Load the config rows for this group/store (optionally a key subset), ordered.
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
     * Livewire hook: authorize the view and seed the editable values (booleans
     * are cast so checkboxes bind correctly).
     *
     * @return void
     */
    public function mount(): void
    {
        parent::mount();

        $this->values = $this->configs()
            ->mapWithKeys(fn (AdminConfig $c) => [
                $c->key => $this->isBooleanType($c->key) ? (bool) (int) $c->value : (string) $c->value,
            ])
            ->all();
    }

    /**
     * Livewire hook: persist a single value the moment it changes (live editing).
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

        AdminConfig::where('key', $key)
            ->where('group', $this->group())
            ->where('store_id', $this->storeId())
            ->update(['value' => $stored]);

        $this->notify('success', gp247_language_render('admin.core.setting_saved'));
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
        ])->layout('gp247-admin::layouts.admin', ['title' => $this->heading()]);
    }
}
