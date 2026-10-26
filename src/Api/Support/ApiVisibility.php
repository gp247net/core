<?php

namespace GP247\Core\Api\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Reuse a storefront listing query as the visibility rule of a public REST API
 * endpoint: the API serves exactly the rows the storefront of the calling domain
 * would show (status, approval, active store, stock display…), without a second
 * copy of those conditions.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-public-field-allowlist
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class ApiVisibility
{
    /**
     * Sub-query of the ids selected by a storefront query (orders and columns dropped).
     *
     * @param Builder $storefrontQuery Result of a model's buildQuery().
     * @return QueryBuilder
     */
    public static function idsOf(Builder $storefrontQuery): QueryBuilder
    {
        $table = $storefrontQuery->getModel()->getTable();

        return $storefrontQuery->reorder()->select($table . '.id')->toBase();
    }

    /**
     * Plain query of $model limited to the rows of $storefrontQuery.
     *
     * @param Builder $storefrontQuery Result of a model's buildQuery().
     * @return Builder
     */
    public static function visible(Builder $storefrontQuery): Builder
    {
        $model = $storefrontQuery->getModel();

        return $model->newQuery()->whereIn($model->getTable() . '.id', self::idsOf($storefrontQuery));
    }
}
