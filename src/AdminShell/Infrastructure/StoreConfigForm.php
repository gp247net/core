<?php

namespace GP247\Core\AdminShell\Infrastructure;

/**
 * ConfigForm variant scoped to the root store (admin_config rows with the empty
 * "" group and store_id = GP247_STORE_ID_ROOT): admin profile, email/SMTP, social
 * links, etc. Multi-store selection is a future enhancement (ADR-005).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-005
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
     * Scope to the root store (single-store default).
     *
     * @return int|string
     */
    protected function storeId()
    {
        return defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1;
    }
}
