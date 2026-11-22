<?php

namespace GP247\Core\Tests\Feature\AdminShell\Support;

use Illuminate\Support\Facades\DB;

/**
 * Test helper: write or remove the site-wide (GLOBAL) password-policy rows of an
 * account group inside the test transaction (DML only, no DDL).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-password-policy-single-source
 */
trait SetsPasswordPolicy
{
    /**
     * Set one policy value (`<audience>_password_<field>`), creating the row if missing.
     *
     * @param string $audience Account group key (admin, customer, ...).
     * @param string $field    min | max | letter | mixedcase | number | symbol.
     * @param string $value    Stored value.
     * @return void
     */
    protected function setPolicy(string $audience, string $field, string $value): void
    {
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $match = ['key' => $audience . '_password_' . $field, 'store_id' => (string) GP247_STORE_ID_GLOBAL];
        if ($table->clone()->where($match)->exists()) {
            $table->clone()->where($match)->update(['value' => $value]);

            return;
        }
        $table->insert($match + [
            'group'  => 'global',
            'code'   => 'password_policy',
            'value'  => $value,
            'sort'   => 0,
            'detail' => 'admin.password_policy.' . ($field === 'mixedcase' ? 'mixed' : $field),
        ]);
    }

    /**
     * Give an account group a full policy (min / max, every character switch off).
     *
     * @param string $audience
     * @param int    $min
     * @param int    $max
     * @return void
     */
    protected function setPolicyRange(string $audience, int $min, int $max): void
    {
        $this->setPolicy($audience, 'min', (string) $min);
        $this->setPolicy($audience, 'max', (string) $max);
        foreach (['letter', 'mixedcase', 'number', 'symbol'] as $switch) {
            $this->setPolicy($audience, $switch, '0');
        }
    }

    /**
     * Remove every policy row of an account group (simulates a site that never had them).
     *
     * @param string $audience
     * @return void
     */
    protected function removePolicy(string $audience): void
    {
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('store_id', (string) GP247_STORE_ID_GLOBAL)
            ->where('key', 'like', $audience . '\_password\_%')
            ->delete();
    }

    /**
     * Stored value of one policy key, or null when the row is missing.
     *
     * @param string $audience
     * @param string $field
     * @return string|null
     */
    protected function policyValue(string $audience, string $field): ?string
    {
        $value = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('store_id', (string) GP247_STORE_ID_GLOBAL)
            ->where('key', $audience . '_password_' . $field)
            ->value('value');

        return $value === null ? null : (string) $value;
    }
}
