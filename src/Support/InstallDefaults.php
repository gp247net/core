<?php

namespace GP247\Core\Support;

/**
 * Product a fresh install is made for, as declared under `gp247-config.install`.
 *
 * A product built on GP247 (S-Cart, GP247 CMS…) names itself through
 * GP247_INSTALL_PRODUCT_NAME / GP247_INSTALL_PRODUCT_URL; the packages build their own
 * default wording from it (store name, admin name and title, home welcome, first menu
 * link), so core never knows which product it runs in. Nothing declared means every
 * package keeps its current wording. Read by the seeders of a fresh install only.
 *
 * @aidlc-unit installer-deploy
 * @aidlc-story US-DEP-install-default-product-name
 * @aidlc-adr installer-deploy_install-default-product-name
 */
final class InstallDefaults
{
    /** Longest product name kept (fits every column the name is written to). */
    public const NAME_MAX = 100;

    /** Longest product web address kept. */
    public const URL_MAX = 255;

    /**
     * Declared product name: trimmed and cut to NAME_MAX, or null when nothing usable is declared.
     *
     * @return string|null
     */
    public static function productName(): ?string
    {
        $declared = self::declared('product_name');
        if (!is_string($declared)) {
            return null;
        }
        $name = trim($declared);

        return $name === '' ? null : mb_substr($name, 0, self::NAME_MAX);
    }

    /**
     * Declared product web address when it is an http(s) URL of at most URL_MAX characters, else null.
     *
     * @return string|null
     */
    public static function productUrl(): ?string
    {
        $declared = self::declared('product_url');
        if (!is_string($declared)) {
            return null;
        }
        $url = trim($declared);
        // WHY a scheme allowlist: the address becomes a menu link every visitor clicks.
        if ($url === '' || mb_strlen($url) > self::URL_MAX || filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    /**
     * Raw value declared under `gp247-config.install`, or null when no configuration is loaded.
     *
     * @param string $key Key under `gp247-config.install`.
     * @return mixed
     */
    private static function declared(string $key)
    {
        // WHY: seeders' data methods are also read without a booted application (language guards).
        if (!function_exists('app') || !app()->bound('config')) {
            return null;
        }

        return config('gp247-config.install.' . $key);
    }

    /**
     * ADMIN_NAME a new store gets: the product name, or the given default.
     *
     * @param string $default Value used when no product is declared.
     * @return string
     */
    public static function adminName(string $default): string
    {
        return self::productName() ?? $default;
    }

    /**
     * ADMIN_TITLE a new store gets: "<product> Admin", or the given default.
     *
     * @param string $default Value used when no product is declared.
     * @return string
     */
    public static function adminTitle(string $default): string
    {
        $name = self::productName();

        return $name === null ? $default : $name . ' Admin';
    }
}
