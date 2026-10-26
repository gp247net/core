<?php

namespace GP247\Core\Api\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Store the admin REST API request works in, and the one place queries are narrowed
 * to it. ROOT means "every store", exactly like a root admin on the web; any other
 * store id restricts rows to that store. Shared by every admin endpoint of every
 * API version so a later fix cannot land in one version only.
 *
 * The value lives on the current request (not in the container) and reading it
 * before ApiAdminAuthorize resolved it throws: an admin controller reached without
 * the `api.admin` middleware fails closed instead of silently seeing every store.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-admin-store-scope
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class ApiStoreScope
{
    private const ATTRIBUTE = 'gp247_api_admin_store';

    /**
     * Remember the store of the current admin API request.
     *
     * @param int|string $storeId
     * @return void
     */
    public static function set($storeId): void
    {
        request()->attributes->set(self::ATTRIBUTE, (string) $storeId);
    }

    /**
     * Store of the current admin API request.
     *
     * @return string
     * @throws \LogicException When no store was resolved for this request.
     */
    public static function current(): string
    {
        $store = request()->attributes->get(self::ATTRIBUTE);
        if ($store === null) {
            throw new \LogicException('Admin API store scope was not resolved: route is missing the api.admin middleware.');
        }
        return $store;
    }

    /**
     * Narrow $query to the current store unless it is ROOT.
     *
     * @param Builder $query
     * @param string  $column Store column, defaults to "<table>.store_id".
     * @return Builder
     */
    public static function apply(Builder $query, ?string $column = null): Builder
    {
        $store = self::current();
        if ($store === (string) GP247_STORE_ID_ROOT) {
            return $query;
        }
        return $query->where($column ?? $query->getModel()->getTable() . '.store_id', $store);
    }
}
