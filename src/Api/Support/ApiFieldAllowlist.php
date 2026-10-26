<?php

namespace GP247\Core\Api\Support;

use Illuminate\Pagination\AbstractPaginator as Paginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;

/**
 * Keep only declared keys of a v1 REST API payload, so a column added to a table
 * never shows up in a public response by accident.
 *
 * A spec is a list of allowed keys; an entry "relation => spec" keeps that relation
 * and filters it recursively (rows of a to-many relation one by one); "relation =>
 * '*'" keeps that relation unchanged.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-public-field-allowlist
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class ApiFieldAllowlist
{
    /**
     * Filter one record.
     *
     * @param mixed $record Model, Arrayable or array; null stays null.
     * @param array $spec   Allowed keys, with nested specs for relations.
     * @return array|null
     */
    public static function one($record, array $spec): ?array
    {
        if ($record === null) {
            return null;
        }
        $row = $record instanceof Arrayable ? $record->toArray() : (array) $record;

        return self::filter($row, $spec);
    }

    /**
     * Filter every item of a paginator while keeping the pagination envelope.
     *
     * @param Paginator $page
     * @param array     $spec
     * @return Paginator
     */
    public static function page(Paginator $page, array $spec): Paginator
    {
        return $page->through(fn ($item) => self::one($item, $spec));
    }

    /**
     * @param array $row
     * @param array $spec
     * @return array
     */
    private static function filter(array $row, array $spec): array
    {
        $out = [];
        foreach ($spec as $key => $value) {
            if (is_int($key)) {
                if (array_key_exists($value, $row)) {
                    $out[$value] = $row[$value];
                }
                continue;
            }
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $nested = $row[$key];
            if ($value === '*' || !is_array($value)) {
                $out[$key] = $nested;
            } elseif (is_array($nested) && Arr::isList($nested)) {
                $out[$key] = array_map(fn ($item) => is_array($item) ? self::filter($item, $value) : $item, $nested);
            } elseif (is_array($nested)) {
                $out[$key] = self::filter($nested, $value);
            } else {
                $out[$key] = $nested;
            }
        }

        return $out;
    }
}
