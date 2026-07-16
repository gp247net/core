<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\Models\AdminLanguage;
use GP247\Core\Models\AdminStore;
use Illuminate\Contracts\View\View;

/**
 * Website information (store_info) screen (ADR-005), the modern Livewire port of
 * the legacy AdminStoreInfoController screen. Single root store: an Active toggle,
 * a left panel of store fields (logo/icon/og_image + contact details, live inline
 * edit) and a right panel of multilingual descriptions (title/keyword/description
 * plus the maintenance copy — maintain_content / maintain_note — folded in from the
 * legacy store_maintain screen, per active language). Each change persists
 * immediately and is Layer-2 authorized
 * (ADR-001); values are gp247_clean'd. Gated by the `store.full` permission.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-009
 * @aidlc-adr ADR-001, ADR-005
 */
class WebsiteInfo extends GP247AdminComponent
{
    protected ?string $permission = 'store.full';

    /** @var array<string, mixed> Editable scalar store fields. */
    public array $store = [];

    /** @var bool Store active flag. */
    public bool $active = true;

    /** @var array<string, array<string, string>> Descriptions keyed by lang => field. */
    public array $desc = [];

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
     * @return int|string The single root store id.
     */
    private function storeId()
    {
        return defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1;
    }

    /**
     * @return \GP247\Core\Models\AdminStore|null
     */
    private function storeModel()
    {
        return AdminStore::with('descriptions')->find($this->storeId());
    }

    /**
     * Load the store record + descriptions into editable state.
     *
     * @return void
     */
    public function mount(): void
    {
        parent::mount();

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
     * Persist a scalar store field the moment it changes (Layer-2 gated). Domain
     * is uniqueness-checked like the legacy controller.
     *
     * @param mixed  $value
     * @param string $key   The changed `store.<key>` segment.
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function updatedStore($value, string $key): void
    {
        $this->authorizeAction('update');

        if (!in_array($key, array_merge(self::MEDIA, self::FIELDS, self::SELECTS, ['domain']), true)) {
            return;
        }

        $clean = gp247_clean((string) $value);

        if ($key === 'domain') {
            $domain = function_exists('gp247_store_process_domain') ? gp247_store_process_domain($clean) : $clean;
            $taken = AdminStore::where('domain', $domain)->where('id', '<>', $this->storeId())->exists();
            if ($taken) {
                $this->notify('error', gp247_language_render('admin.store.domain_exist'));

                return;
            }
            $this->store['domain'] = $domain;
            AdminStore::where('id', $this->storeId())->update(['domain' => $domain]);
        } elseif ($key === 'template') {
            $this->activateTemplate($clean);
        } else {
            AdminStore::where('id', $this->storeId())->update([$key => $clean]);
        }

        $this->notify('success', gp247_language_render('admin.setting_saved'));
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
     * Persist a per-language description field (Layer-2 gated). The wire path is
     * `desc.<lang>.<field>`, so $path arrives as "<lang>.<field>".
     *
     * @param mixed  $value
     * @param string $path
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function updatedDesc($value, string $path): void
    {
        $this->authorizeAction('update');

        [$lang, $field] = array_pad(explode('.', $path, 2), 2, '');
        if ($lang === '' || !in_array($field, self::DESC_FIELDS, true)) {
            return;
        }

        // WHY: rich HTML fields keep their markup (raw); plain-text fields are XSS-cleaned.
        $value = (string) $value;
        $value = in_array($field, self::RICH_FIELDS, true) ? $value : gp247_clean($value);

        AdminStore::updateDescription([
            'storeId' => $this->storeId(),
            'lang' => $lang,
            'name' => $field,
            'value' => $value,
        ]);

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
            'isRoot' => $this->storeId() === (defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1),
        ])->layout('gp247-admin::layouts.admin', ['title' => gp247_language_render('admin.store.title')]);
    }
}
