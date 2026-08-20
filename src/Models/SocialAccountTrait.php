<?php

namespace GP247\Core\Models;

use Illuminate\Support\Facades\Schema;

/**
 * Trait Model.
 */
trait SocialAccountTrait
{
    /**
     * Determine whether the LoginSocial plugin is truly usable.
     *
     * class_exists() alone is not enough: the plugin source ships inside
     * app/GP247/Plugins/LoginSocial, so the SocialAccount class autoloads
     * (class_exists === true) even when the plugin was never installed and
     * the social_accounts table does not exist. Guarding only on the class
     * lets callers eager-load socialAccount() and crash with a "table not
     * found" QueryException. The real operating condition is the DB table.
     *
     * @return bool True when the class autoloads AND the DB table exists.
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-LW-001
     */
    public static function socialAccountEnabled(): bool
    {
        return class_exists(\App\GP247\Plugins\LoginSocial\Models\SocialAccount::class)
            && Schema::hasTable('social_accounts');
    }

    /**
     * Polymorphic one-to-one to the LoginSocial account, when available.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne|null
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-LW-001
     */
    function socialAccount()
    {
        if (self::socialAccountEnabled()) {
            return $this->morphOne(
                \App\GP247\Plugins\LoginSocial\Models\SocialAccount::class,
                    'user'
                );
            }
        return null;
    }

    /**
     * Whether the LoginSocial plugin is actually operational.
     *
     * Gate on the real operating condition — the SocialAccount class autoloads
     * AND its backing table exists — not just class presence. WHY: the plugin
     * source ships under app/GP247, so class_exists() stays true even when the
     * plugin was never installed at the DB layer and the table is missing;
     * eager-loading the socialAccount() relation in that state crashes.
     *
     * @return bool True when the socialAccount() relation is safe to query.
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-LW-001
     */
    public static function socialAccountEnabled(): bool
    {
        // Memoize: called per dashboard render (blade + getTopCustomer) and the
        // result cannot change within a request.
        static $enabled = null;
        if ($enabled !== null) {
            return $enabled;
        }

        $socialAccountClass = \App\GP247\Plugins\LoginSocial\Models\SocialAccount::class;
        if (!class_exists($socialAccountClass)) {
            return $enabled = false;
        }

        // Derive the table name from the model so it stays correct if the
        // plugin renames it — never hardcode the table here.
        return $enabled = \Illuminate\Support\Facades\Schema::hasTable(
            (new $socialAccountClass)->getTable()
        );
    }
}
