<?php

namespace GP247\Core\AdminShell\Infrastructure;

/**
 * ConfigForm variant for the core admin config screens (admin_config rows with the
 * empty "" group): admin profile, email/SMTP, etc. The base tier is the ROOT store
 * (not the virtual GLOBAL tier used by plugin config), so ROOT holds the shared
 * values and each sub-store overrides on top.
 *
 * Per-store scope is opted in here (storeScoped() = true), so every core config
 * screen becomes store-scoped at once when a multi-store/multi-vendor plugin is
 * installed (gp247_store_check_multi_domain_installed): a root admin picks a store
 * to override, a bound store-admin is locked to their own store, and a single-store
 * site sees no picker and keeps the exact previous behaviour. Before this, both root
 * and store admins read/wrote the same ROOT rows (the reported cross-store bug).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005, US-AUI-core-config-store-scope
 * @aidlc-adr ADR-005, admin-shell_core-config-store-scope
 */
abstract class StoreConfigForm extends ConfigForm
{
    /**
     * These settings live under the empty config group.
     *
     * @return string
     */
    protected function group(): string
    {
        return '';
    }

    /**
     * Base (shared) tier for these screens: the ROOT store. Sub-store rows override it.
     *
     * @return int|string
     */
    protected function storeId()
    {
        return defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1;
    }

    /**
     * Opt into per-store scoping for all core config screens (guarded at runtime by
     * gp247_store_check_multi_domain_installed(), so single-store sites are unaffected).
     *
     * @return bool
     */
    protected function storeScoped(): bool
    {
        return true;
    }

    /**
     * The base tier here is the ROOT store, so the picker's first item is labelled as
     * the root config rather than the generic "global" tier (which core config lacks).
     *
     * @return string
     */
    public function scopeBaseLabel(): string
    {
        return gp247_language_quickly('admin.store.scope_root', 'Root config (ROOT)');
    }
}
