<?php

namespace GP247\Core\AdminShell\Infrastructure;

use GP247\Core\Models\AdminStore;

/**
 * Reusable store-scope UI primitives for admin Livewire screens that are NOT built
 * on ResourcePanel (e.g. the Form/List-pair components: BannerForm/BannerList).
 * Exposes exactly the public methods the shared blade partials
 * `gp247-admin::partials.store-scope-picker` / `store-scope-line` call, plus the
 * create-store resolver, so those screens get the same behaviour as the
 * ResourcePanel screens without duplicating markup.
 *
 * A host opts in by overriding storeScopeOptIn() to return true. When the opt-in
 * is off, or no multi-store/multi-vendor plugin is installed, every method is a
 * no-op and the partials render nothing (single-store parity). Edit-time store
 * resolution for related-option filtering is model-specific and therefore stays in
 * the host (a currentStore() reading the record's own store_id from the DB).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-SADM-store-content-assignment
 * @aidlc-adr admin-shell_store-scoped-resource-panel
 */
trait HasStoreScopeUi
{
    /**
     * Selected store id for the create picker (root admin); '' = none yet. On edit
     * it is set to the record's store for the read-only display.
     *
     * @var string
     */
    public string $formStoreId = '';

    /**
     * Whether this screen opts into store scoping. Hosts override to return true.
     *
     * @return bool
     */
    protected function storeScopeOptIn(): bool
    {
        return false;
    }

    /**
     * Store scoping is active when the screen opted in AND a multi-store or
     * multi-vendor plugin is installed. Off → the partials render nothing.
     *
     * @return bool
     */
    public function storeScopeActive(): bool
    {
        return $this->storeScopeOptIn()
            && function_exists('gp247_store_check_multi_domain_installed')
            && gp247_store_check_multi_domain_installed();
    }

    /**
     * The admin's current store context (ROOT at root admin; the assigned/selected
     * store for a store-admin or the Pro switcher).
     *
     * @return int|string
     */
    protected function storeContext()
    {
        return session('adminStoreId', defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1);
    }

    /**
     * @return bool Whether the context is the root store (no sub-store scope).
     */
    protected function isRootScope(): bool
    {
        $root = defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1;

        return (string) $this->storeContext() === (string) $root;
    }

    /**
     * Server-authoritative store for a NEW record: the context when scoped (forced),
     * else the validated picked store at root.
     *
     * @return int|string
     */
    protected function resolveCreateStore()
    {
        if (!$this->storeScopeActive() || !$this->isRootScope()) {
            return $this->storeContext();
        }

        return $this->formStoreId;
    }

    /**
     * Localised store title for a record's store_id (list "store line").
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function storeLabel($storeId): string
    {
        if ($storeId === null || $storeId === '') {
            return '';
        }
        $titles = AdminStore::getListTitle();

        return (string) ($titles[$storeId] ?? $storeId);
    }

    /**
     * Store options (id => title) for the create picker.
     *
     * @return array<int|string, string>
     */
    public function storeOptions(): array
    {
        return AdminStore::getListTitle();
    }

    /**
     * Whether to show the store PICKER (store-scoped create at root admin). When
     * false but store-scoped is active, the blade shows the store read-only.
     *
     * @return bool
     */
    public function showStorePicker(): bool
    {
        $editing = property_exists($this, 'editingId') ? $this->editingId : null;

        return $this->storeScopeActive() && $editing === null && $this->isRootScope();
    }

    /**
     * Localised label of the form's current store (read-only display on edit /
     * scoped create). Uses formStoreId (set from the record on edit) or the context.
     *
     * @return string
     */
    public function currentStoreLabel(): string
    {
        $store = $this->formStoreId !== '' ? $this->formStoreId : $this->storeContext();

        return $this->storeLabel($store);
    }

    /**
     * Validation rule to require a valid store on scoped create at root admin. A
     * FormComponent host merges this into rules() so it can never persist a blank
     * store_id (parity with ResourcePanel::save()). Empty otherwise (edit / scoped
     * context / single-store).
     *
     * @return array<string, mixed>
     */
    protected function storeScopeCreateRules(): array
    {
        if (!$this->showStorePicker()) {
            return [];
        }

        return ['formStoreId' => ['required', \Illuminate\Validation\Rule::exists((new AdminStore)->getTable(), 'id')]];
    }

    /**
     * Localised messages for the store-required rule, to merge into messages().
     *
     * @return array<string, string>
     */
    protected function storeScopeMessages(): array
    {
        return [
            'formStoreId.required' => gp247_language_render('admin.store.select_store_required'),
            'formStoreId.exists'   => gp247_language_render('admin.store.select_store_required'),
        ];
    }
}
