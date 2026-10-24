<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\AdminShell\Infrastructure\HasStoreScopeUi;
use GP247\Core\Models\AdminLanguage;
use GP247\Core\Models\AdminStore;
use GP247\Core\Models\AdminStoreDescription;
use Illuminate\Contracts\View\View;

/**
 * Website information (store_info) screen (ADR-005), the modern Livewire port of
 * the legacy AdminStoreInfoController screen. Single root store: an Active toggle,
 * a left panel of store fields (logo/icon/og_image + contact details) and a right
 * panel of multilingual descriptions (title/keyword/description plus the
 * maintenance copy — maintain_content / maintain_note — folded in from the legacy
 * store_maintain screen, per active language). Field edits persist only on the
 * explicit Save action (save()); the Active toggle alone persists immediately.
 * Every write is Layer-2 authorized (ADR-001); values are gp247_clean'd. Gated by
 * the `store.full` permission.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-009
 * @aidlc-adr ADR-001, ADR-005
 */
class WebsiteInfo extends GP247AdminComponent
{
    use HasStoreScopeUi;

    protected ?string $permission = 'store.full';

    /**
     * Opt into per-store scoping (guarded at runtime by
     * gp247_store_check_multi_domain_installed(), so single-store sites are unaffected):
     * a store-admin edits their own bound store, the root admin may pick a sub-store.
     * (mod 20260905T230324, ADR admin-shell_website-info-store-scope.)
     *
     * @return bool
     */
    protected function storeScopeOptIn(): bool
    {
        return true;
    }

    // WHY: this screen's slug ('store.full') is not an admin_<resource> convention
    // name, so the base cannot derive the screen path from it — declare the real
    // route path so URI-based authorization (ADR-001 Layer-2) resolves correctly.
    protected ?string $screenUri = 'gp247_admin/store_info';

    /** @var array<string, mixed> Editable scalar store fields. */
    public array $store = [];

    /** @var bool Store active flag. */
    public bool $active = true;

    /** @var array<string, array<string, string>> Descriptions keyed by lang => field. */
    public array $desc = [];

    /**
     * Template key awaiting confirmation. Set when Save finds a different template
     * selected; the destructive switch (removeStore/setupStore) only runs after
     * confirmTemplateSwitch(). Null when no switch is pending.
     *
     * @var string|null
     */
    public ?string $pendingTemplate = null;

    /** Store scalar text fields editable on this screen (excludes domain, handled separately). */
    private const FIELDS = ['phone', 'long_phone', 'email', 'time_active', 'address', 'office', 'warehouse'];

    /** Media (image path) fields rendered with the media picker. */
    private const MEDIA = ['logo', 'icon', 'og_image'];

    /** Select fields (language always; currency needs shop; template needs front). */
    private const SELECTS = ['language', 'currency', 'template'];

    /**
     * Per-language description fields. `maintain_content` / `maintain_note` are
     * folded in from the legacy store_maintain screen so the maintenance copy is
     * edited on this same screen (descriptions are persisted identically).
     */
    private const DESC_FIELDS = ['name', 'keyword', 'description', 'maintain_content', 'maintain_note'];

    /**
     * Description fields holding admin-authored rich HTML (TinyMCE). These are stored
     * raw — like the legacy store_maintain / CMS content fields — because gp247_clean()
     * htmlspecialchars-escapes its input and would break HTML rendering. Safe here:
     * the screen is RBAC-gated (store.full) and every write is Layer-2 authorized.
     */
    private const RICH_FIELDS = ['maintain_content'];

    /**
     * @return int|string The ROOT store id (default scope + single-store fallback).
     */
    private function rootStoreId()
    {
        return defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1;
    }

    /**
     * The store this screen edits — the "effective store" (mod 20260905T230324;
     * root picker dropped mod 20260905T233706): the ROOT admin always edits ROOT
     * here (per-store identity is managed at gp247_admin/MultiStore, so a picker
     * would duplicate it); a bound store-admin edits their own store (storeContext);
     * scope off / single-store → ROOT (parity). No {id} on the route ⇒ no IDOR.
     *
     * @return int|string
     */
    private function storeId()
    {
        if (!$this->storeScopeActive() || $this->isRootScope()) {
            return $this->rootStoreId();
        }

        return $this->storeContext();
    }

    /**
     * @return \GP247\Core\Models\AdminStore|null
     */
    private function storeModel()
    {
        return AdminStore::with('descriptions')->find($this->storeId());
    }

    /**
     * Load the effective store record into editable state.
     *
     * @return void
     */
    public function mount(): void
    {
        parent::mount();

        $this->loadStore();
    }

    /**
     * Load the effective store's scalar fields + multilingual descriptions into the
     * editable component state.
     *
     * @return void
     */
    private function loadStore(): void
    {
        $model = $this->storeModel();
        if ($model === null) {
            return;
        }

        foreach (array_merge(self::MEDIA, self::FIELDS, self::SELECTS, ['domain']) as $field) {
            $this->store[$field] = (string) ($model->{$field} ?? '');
        }
        $this->active = (bool) (int) $model->active;

        $descriptions = $model->descriptions->keyBy('lang');
        foreach (array_keys($this->languages()) as $code) {
            foreach (self::DESC_FIELDS as $field) {
                $this->desc[$code][$field] = (string) ($descriptions[$code][$field] ?? '');
            }
        }
    }

    /**
     * Active languages keyed by code (name + icon) for the description panel.
     *
     * @return array<string, mixed>
     */
    public function languages(): array
    {
        return AdminLanguage::getListActive()->all();
    }

    /**
     * Store fields the Save action may write. Overridable so a narrower screen
     * (e.g. the MultiVendor vendor store-info) can shrink the allow-list; the
     * domain is offered only in ROOT context (platform-owner only).
     *
     * @return string[]
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-UI-009
     */
    protected function writableStoreFields(): array
    {
        $fields = array_merge(self::MEDIA, self::FIELDS, self::SELECTS);

        // WHY domain is platform-owner only: it drives multi-domain routing and the
        // store-admin domain guard. A bound store-admin (isRootScope() === false)
        // must never change it — dropped server-side even if the field were forged
        // (the blade also hides it for non-root). mod 20260905T230324,
        // RISK-SEC-website-info-domain-hijack.
        if ($this->isRootScope()) {
            $fields[] = 'domain';
        }

        return $fields;
    }

    /**
     * Persist every edited store field and per-language description in one go
     * (Layer-2 gated). Field edits are deferred until this explicit Save so IME
     * users (Enter / input-mode switches mid-composition) never persist partial
     * text. All-or-nothing on validation: a taken domain aborts before any write.
     * A changed template is not written here — it opens the destructive-switch
     * confirmation (confirmTemplateSwitch) after the other fields are saved.
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-UI-009
     * @aidlc-adr ADR-001, ADR-005
     */
    public function save(): void
    {
        $this->authorizeAction('update');

        $storeId = $this->storeId();
        $fields = $this->writableStoreFields();

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = gp247_clean((string) ($this->store[$field] ?? ''));
        }

        if (array_key_exists('domain', $values)) {
            $domain = function_exists('gp247_store_process_domain') ? gp247_store_process_domain($values['domain']) : $values['domain'];
            // WHY only when changed: an unchanged (possibly legacy-duplicated or empty)
            // domain must not block saving the other fields.
            $changed = $domain !== (string) (AdminStore::where('id', $storeId)->value('domain') ?? '');
            if ($changed && AdminStore::where('domain', $domain)->where('id', '<>', $storeId)->exists()) {
                $this->notify('error', gp247_language_render('admin.store.domain_exist'));

                return;
            }
            $values['domain'] = $domain;
            $this->store['domain'] = $domain;
        }

        // WHY: switching template runs removeStore()/setupStore(), which DELETE the
        // current template's homepage layout blocks + banners and reseed sample data
        // — destructive and irreversible (RISK-OPS-template-switch-data-loss). Never
        // write it with the other fields; defer behind the confirmation modal
        // (ADR admin-shell-rbac_template-switch-confirmation).
        $template = $values['template'] ?? '';
        unset($values['template']);

        if ($values !== []) {
            AdminStore::where('id', $storeId)->update($values);
        }
        $this->saveDescriptions($storeId);

        $current = (string) (AdminStore::where('id', $storeId)->value('template') ?? '');
        if (in_array('template', $fields, true) && $template !== '' && $template !== $current) {
            // WHY: the modal is driven off this property (an Alpine bridge in the view
            // watches $wire.pendingTemplate), NOT a server dispatch('open-modal') —
            // Livewire wraps dispatched params so the modal's `$event.detail === name`
            // check would never match.
            $this->pendingTemplate = $template;
        }

        $this->notify('success', gp247_language_render('admin.setting_saved'));
    }

    /**
     * Upsert the per-language descriptions held in state, for active languages
     * only (a forged `desc.<lang>` for an inactive/unknown code is ignored).
     *
     * @param int|string $storeId
     * @return void
     */
    private function saveDescriptions($storeId): void
    {
        foreach (array_keys($this->languages()) as $code) {
            $row = [];
            foreach (self::DESC_FIELDS as $field) {
                $value = (string) ($this->desc[$code][$field] ?? '');
                // WHY: rich HTML fields keep their markup (raw); plain-text fields are XSS-cleaned.
                $row[$field] = in_array($field, self::RICH_FIELDS, true) ? $value : gp247_clean($value);
            }

            AdminStoreDescription::where('store_id', $storeId)->where('lang', $code)->exists()
                ? AdminStoreDescription::where('store_id', $storeId)->where('lang', $code)->update($row)
                : AdminStoreDescription::insert(['store_id' => $storeId, 'lang' => (string) $code] + $row);
        }
    }

    /**
     * Execute the deferred template switch after the admin confirms in the modal.
     * Runs the same activateTemplate() (removeStore + setupStore) the field used
     * to run directly on change, now gated behind explicit confirmation.
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-AUI-008
     * @aidlc-adr admin-shell-rbac_template-switch-confirmation
     */
    public function confirmTemplateSwitch(): void
    {
        $this->authorizeAction('update');

        if ($this->pendingTemplate === null || $this->pendingTemplate === '') {
            return;
        }

        $this->activateTemplate($this->pendingTemplate);
        $this->store['template'] = $this->pendingTemplate;
        $this->pendingTemplate = null; // closes the modal via the client bridge
        $this->notify('success', gp247_language_render('admin.setting_saved'));
    }

    /**
     * Abort a pending template switch: reset the bound value back to the persisted
     * template so the searchable-select ($wire.$watch) snaps the dropdown back to
     * the current template. Nothing destructive is executed.
     *
     * @return void
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-AUI-008
     * @aidlc-adr admin-shell-rbac_template-switch-confirmation
     */
    public function cancelTemplateSwitch(): void
    {
        $this->store['template'] = (string) (AdminStore::where('id', $this->storeId())->value('template') ?? '');
        $this->pendingTemplate = null; // closes the modal via the client bridge
    }

    /**
     * Switch the store to a different template by invoking the outgoing
     * template's `AppConfig::removeStore()` hook, then the new template's
     * `AppConfig::setupStore()` hook — the same install()/uninstall() pairing
     * each AppConfig uses for itself, and the same mechanism
     * `AdminStore::setUpDataDefault()` uses when a brand-new store is
     * provisioned — instead of only writing the `template` column directly.
     * setupStore() seeds home-page layout blocks and sample banners; skipping
     * removeStore() first would leave the outgoing template's blocks/banners
     * behind as orphaned data tied to a template the store no longer uses.
     *
     * @param string $key Template key (e.g. "Default", "Demo").
     * @return void
     */
    private function activateTemplate(string $key): void
    {
        $storeId = $this->storeId();

        // Read the *current* DB value before setupStore() below overwrites the
        // `template` column — this is the last point the outgoing key is known.
        $oldKey = (string) (AdminStore::where('id', $storeId)->value('template') ?? '');
        if ($oldKey !== '' && $oldKey !== $key) {
            $oldClassTemplate = 'App\\GP247\\Templates\\' . $oldKey . '\\AppConfig';
            if (class_exists($oldClassTemplate) && method_exists($oldClassTemplate, 'removeStore')) {
                (new $oldClassTemplate())->removeStore($storeId);
            }
        }

        $classTemplate = 'App\\GP247\\Templates\\' . $key . '\\AppConfig';

        if (class_exists($classTemplate) && method_exists($classTemplate, 'setupStore')) {
            (new $classTemplate())->setupStore($storeId);
        } else {
            // Fallback: no AppConfig::setupStore() hook for this template —
            // still persist the choice so the field stays usable.
            AdminStore::where('id', $storeId)->update(['template' => $key]);
        }
    }

    /**
     * Persist the Active toggle (Layer-2 gated).
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function updatedActive(): void
    {
        $this->authorizeAction('update');

        AdminStore::where('id', $this->storeId())->update(['active' => $this->active ? 1 : 0]);
        $this->notify('success', gp247_language_render('admin.setting_saved'));
    }

    /**
     * Whether `gp247/shop`'s currency table is actually queryable: the class must
     * be autoloadable AND the `shop_currency` table must exist. `gp247/shop` is a
     * mandatory composer dependency of this app, so its classes are always
     * autoloadable even when the site never ran `gp247:shop-install` (the command
     * that migrates the shop-specific tables) — checking only `class_exists()`
     * (or composer package presence) would still crash on a fresh core-only site
     * (NFR-MAINT-001). The table check is the only reliable signal.
     *
     * @return bool
     */
    private function shopCurrencyAvailable(): bool
    {
        if (!class_exists(\GP247\Shop\Models\ShopCurrency::class)) {
            return false;
        }

        return \Illuminate\Support\Facades\Schema::connection(GP247_DB_CONNECTION)
            ->hasTable(GP247_DB_PREFIX . 'shop_currency');
    }

    /**
     * Whether `gp247/front`'s template list is actually queryable. Mirrors
     * {@see shopCurrencyAvailable()}: `gp247/front` is a mandatory composer
     * dependency, so its helpers are always autoloadable even when the site
     * never ran `gp247:front-install` (the command that migrates the
     * front-specific tables and seeds the template list). Without the table
     * check, a core-only site would still show a template option (built from
     * the empty `admin_config` "Templates" group) even though front was never
     * installed (NFR-MAINT-001). `front_layout_block` — which stores each
     * homepage layout block against its owning `template` — is created by
     * that same install migration, so its presence is a reliable, template-
     * relevant install marker (unlike an unrelated table such as banners).
     *
     * @return bool
     */
    private function frontTemplateAvailable(): bool
    {
        if (!function_exists('gp247_front_get_all_template_installed')) {
            return false;
        }

        return \Illuminate\Support\Facades\Schema::connection(GP247_DB_CONNECTION)
            ->hasTable(GP247_DB_PREFIX . 'front_layout_block');
    }

    /**
     * @return View
     */
    public function render(): View
    {
        $languages = $this->languages();

        $languageOptions = [];
        foreach ($languages as $code => $lang) {
            $languageOptions[$code] = $lang->name;
        }

        // Currency needs gp247/shop; template needs gp247/front (empty = row hidden).
        $currencyOptions = $this->shopCurrencyAvailable()
            ? (array) \GP247\Shop\Models\ShopCurrency::getCodeActive()
            : [];

        $templateOptions = $this->frontTemplateAvailable()
            ? (array) gp247_front_get_all_template_installed()
            : [];

        return view('gp247-admin::livewire.website-info', [
            'languages' => $languages,
            'mediaFields' => self::MEDIA,
            'fields' => self::FIELDS,
            'languageOptions' => $languageOptions,
            'currencyOptions' => $currencyOptions,
            'templateOptions' => $templateOptions,
            // WHY isRootScope() (not storeId===ROOT): domain is editable only in ROOT
            // context (the ROOT admin editing ROOT); a bound store-admin (non-root
            // context) never sees it (mod 20260905T230324, RISK-SEC-website-info-domain-hijack).
            'isRoot' => $this->isRootScope(),
        ])->layout('gp247-admin::layouts.admin', ['title' => gp247_language_render('admin.store.title')]);
    }
}
