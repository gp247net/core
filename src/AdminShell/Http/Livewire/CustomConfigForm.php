<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\Models\AdminConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Custom config screen (admin_custom_config group) — the modern Livewire port of
 * the legacy "Cấu hình tùy chỉnh" tab (AdminStoreConfigController). A free-form
 * key/value editor for the root store: it lists every admin_custom_config row
 * (the seeded social links are just defaults among them), supports adding a new
 * config (detail/key/value), deleting a row and editing values live inline.
 *
 * Each mutation is Layer-2 authorized (ADR-001) and gp247_clean'd; keys are unique
 * per store. Gated by `admin_config`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-001, ADR-005
 */
class CustomConfigForm extends GP247AdminComponent
{
    /** The admin_config "code" grouping free-form custom configs. */
    private const CODE = 'admin_custom_config';

    protected ?string $permission = 'admin_config';

    /** @var array<string, string> Editable key => value map for inline live editing. */
    public array $values = [];

    /** @var string New config human-readable detail/label (add row). */
    public string $newDetail = '';

    /** @var string New config key (add row). */
    public string $newKey = '';

    /** @var string New config value (add row). */
    public string $newValue = '';

    /**
     * Root store scope (single-store default, mirrors the other store config screens).
     *
     * @return int|string
     */
    private function storeId()
    {
        return defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1;
    }

    /**
     * Load the custom-config rows for the root store, ordered by sort then key.
     *
     * @return Collection<int, AdminConfig>
     */
    private function rows(): Collection
    {
        return AdminConfig::where('code', self::CODE)
            ->where('store_id', $this->storeId())
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
            ->where('store_id', $this->storeId())
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
            ->where('store_id', $this->storeId())
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
            'store_id' => $this->storeId(),
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
            ->where('store_id', $this->storeId())
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
        ])->layout('gp247-admin::layouts.admin', ['title' => $heading]);
    }
}
