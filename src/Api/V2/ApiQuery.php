<?php

namespace GP247\Core\Api\V2;

use GP247\Core\Api\Support\ApiErrorResponder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Query parameters and envelopes of the v2 REST API.
 *
 * Lists accept `filter[<name>]`, `sort=<name>|-<name>`, `q`, `page[number]` and
 * `page[size]` (default 20, capped at 100). Filter and sort names come from a list
 * declared by each endpoint — anything else is a 422, input never reaches the query
 * as a column name. Bodies: `{"data": …}` for one record,
 * `{"data": […], "meta": {…}, "links": {…}}` for a list.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-v2-query-parameters, US-CMP-api-v2-contract
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class ApiQuery
{
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 100;

    /**
     * Apply the declared query options to $query and answer one page.
     *
     * @param Builder  $query   Base query (visibility/store scope already applied).
     * @param Request  $request
     * @param callable $toArray fn(Model $row): array — the resource of one row.
     * @param array<string, callable> $filters name => fn(Builder $q, string $value): void.
     * @param array<string, string>   $sorts   name => qualified column.
     * @param callable|null           $search  fn(Builder $q, string $term): void for `q`.
     * @param bool                    $changedSince Admin lists: accept `updated_since` (sync mode, keyset paging).
     * @param ApiShapeSelection|null  $shape   fields/include chosen by the request (null = the declared resource as is).
     * @return JsonResponse
     * @throws ValidationException When a filter/sort name is not declared.
     *
     * @aidlc-story US-api-sync-changed-since-filter, US-CMP-api-sparse-fieldsets, US-CMP-api-include-relations
     * @aidlc-adr api-sync_keyset-changed-since, compat-foundation_api-http-caching-and-shaping
     */
    public static function page(Builder $query, Request $request, callable $toArray, array $filters = [], array $sorts = [], ?callable $search = null, bool $changedSince = false, ?ApiShapeSelection $shape = null): JsonResponse
    {
        $requested = (array) $request->query('filter', []);
        $unknown = array_diff(array_keys($requested), array_keys($filters));
        if ($unknown) {
            throw ValidationException::withMessages(['filter' => ['Unknown filter: ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', array_keys($filters))]]);
        }
        foreach ($requested as $name => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $filters[$name]($query, (string) $value);
            }
        }

        foreach (['q', 'sort'] as $scalar) {
            if (!is_string($request->query($scalar, ''))) {
                throw ValidationException::withMessages([$scalar => ['The ' . $scalar . ' parameter must be a single value.']]);
            }
        }

        $term = trim($request->query('q', ''));
        if ($term !== '' && $search) {
            $search($query, $term);
        }

        $query->reorder();
        if ($changedSince && $request->query('updated_since') !== null) {
            return self::changedSincePage($query, $request, $toArray, $shape);
        }
        $sort = $request->query('sort', '');
        if ($sort !== '') {
            $field = ltrim($sort, '-');
            if (!isset($sorts[$field])) {
                throw ValidationException::withMessages(['sort' => ['Unknown sort field: ' . $field . '. Allowed: ' . implode(', ', array_keys($sorts))]]);
            }
            $query->orderBy($sorts[$field], str_starts_with($sort, '-') ? 'desc' : 'asc');
        }
        // WHY: a final unique key makes LIMIT/OFFSET pages stable, so a client syncing
        // page by page neither skips nor repeats rows.
        $query->orderBy($query->getModel()->getTable() . '.' . $query->getModel()->getKeyName());

        $page = (array) $request->query('page', []);
        $size = min(self::MAX_PAGE_SIZE, max(1, (int) ($page['size'] ?? self::DEFAULT_PAGE_SIZE)));
        $number = max(1, (int) ($page['number'] ?? 1));
        $paginator = $query->paginate($size, ['*'], 'page', $number);

        $link = fn (?int $n) => $n === null ? null : $request->url() . '?' . http_build_query(array_merge($request->query(), ['page' => ['number' => $n, 'size' => $size]]));

        return response()->json([
            'data'  => $paginator->getCollection()->map(fn ($row) => self::shaped($row, $toArray, $shape, $request))->values(),
            'meta'  => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
            'links' => [
                'first' => $link(1),
                'last'  => $link($paginator->lastPage()),
                'prev'  => $link($paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null),
                'next'  => $link($paginator->hasMorePages() ? $paginator->currentPage() + 1 : null),
            ],
        ]);
    }

    /**
     * Sync mode of an admin list: rows with `updated_at >= updated_since`, oldest change
     * first, keyset-paged by `page[after]` (a cursor on `updated_at` + key) so a row changed
     * between two pages never pushes another row out of sight. No COUNT, no page numbers.
     *
     * @param Builder  $query   Filters, search and scope already applied; order cleared.
     * @param Request  $request
     * @param callable $toArray
     * @param ApiShapeSelection|null $shape
     * @return JsonResponse
     * @throws ValidationException On a bad mark, a forged cursor, `sort` or `page[number]`.
     *
     * @aidlc-unit api-sync
     * @aidlc-story US-api-sync-changed-since-filter
     * @aidlc-adr api-sync_keyset-changed-since
     */
    private static function changedSincePage(Builder $query, Request $request, callable $toArray, ?ApiShapeSelection $shape = null): JsonResponse
    {
        $since = $request->query('updated_since');
        if (!is_string($since) || !preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $since)) {
            throw ValidationException::withMessages(['updated_since' => ['updated_since must be an ISO-8601 date-time, e.g. 2026-10-31T08:00:00+07:00.']]);
        }
        $page = (array) $request->query('page', []);
        if ((string) $request->query('sort', '') !== '' || isset($page['number'])) {
            throw ValidationException::withMessages(['updated_since' => ['updated_since sorts by update time and pages with page[after]; drop sort and page[number].']]);
        }
        $after = null;
        if (isset($page['after'])) {
            $after = is_string($page['after']) ? \GP247\Core\Api\Sync\ChangeCursor::decode($page['after']) : null;
            if ($after === null) {
                throw ValidationException::withMessages(['page' => ['page[after] is not a cursor from this list.']]);
            }
        }

        $timezone = config('app.timezone');
        $from = \Illuminate\Support\Carbon::parse($since, $timezone)->setTimezone($timezone)->format('Y-m-d H:i:s');
        $table = $query->getModel()->getTable();
        $keyColumn = $table . '.' . $query->getModel()->getKeyName();
        $updated = $table . '.updated_at';
        $query->where($updated, '>=', $from);
        if ($after !== null) {
            [$at, $key] = $after;
            $query->where(fn (Builder $q) => $q->where($updated, '>', $at)
                ->orWhere(fn (Builder $same) => $same->where($updated, $at)->where($keyColumn, '>', $key)));
        }
        $size = min(self::MAX_PAGE_SIZE, max(1, (int) ($page['size'] ?? self::DEFAULT_PAGE_SIZE)));
        $rows = $query->orderBy($updated)->orderBy($keyColumn)->limit($size + 1)->get();
        $hasMore = $rows->count() > $size;
        $rows = $rows->take($size)->values();

        $last = $rows->last();
        $lastAt = $last ? (string) $last->getRawOriginal('updated_at') : null;
        $nextAfter = $last ? \GP247\Core\Api\Sync\ChangeCursor::encode($lastAt, (string) $last->getKey()) : null;
        $syncUntil = $lastAt ? \Illuminate\Support\Carbon::parse($lastAt, $timezone)->toIso8601String() : null;
        $next = $hasMore
            ? $request->url() . '?' . http_build_query(array_merge($request->query(), ['page' => ['size' => $size, 'after' => $nextAfter]]))
            : null;

        return response()->json([
            'data'  => $rows->map(fn ($row) => self::shaped($row, $toArray, $shape, $request))->values(),
            'meta'  => [
                'per_page'   => $size,
                'next_after' => $nextAfter,
                'has_more'   => $hasMore,
                'sync_until' => $syncUntil,
            ],
            'links' => ['next' => $next],
        ]);
    }

    /**
     * Answer one record, or 404 when there is none.
     *
     * @param mixed                  $row
     * @param callable               $toArray
     * @param ApiShapeSelection|null $shape fields/include chosen by the request.
     * @return JsonResponse
     *
     * @aidlc-story US-CMP-api-v2-contract, US-CMP-api-sparse-fieldsets, US-CMP-api-include-relations
     */
    public static function one($row, callable $toArray, ?ApiShapeSelection $shape = null): JsonResponse
    {
        return $row ? response()->json(['data' => self::shaped($row, $toArray, $shape, request())]) : ApiErrorResponder::notFound();
    }

    /**
     * The resource of one row, shaped by the request's fields/include when there are any.
     *
     * @param mixed                  $row
     * @param callable               $toArray
     * @param ApiShapeSelection|null $shape
     * @param Request                $request
     * @return mixed
     */
    private static function shaped($row, callable $toArray, ?ApiShapeSelection $shape, Request $request)
    {
        $data = $toArray($row);

        return $shape !== null && is_array($data) ? $shape->apply($data, $row, $request) : $data;
    }

    /**
     * Escape a term for a LIKE pattern (the value itself is bound, never concatenated).
     *
     * @param string $term
     * @return string Pattern "%term%".
     */
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }

    /**
     * Language of the request: `lang` when it is an active language, else the site's.
     *
     * @param Request $request
     * @return string
     */
    public static function lang(Request $request): string
    {
        $lang = (string) $request->query('lang', '');
        $active = function_exists('gp247_language_all') ? collect(gp247_language_all())->keys()->all() : [];

        return in_array($lang, $active, true) ? $lang : gp247_get_locale();
    }

    /**
     * Text fields of the description of $lang (falls back to the first one).
     *
     * @param \Illuminate\Support\Collection|null $descriptions
     * @param string                              $lang
     * @param string[]                            $fields
     * @return array<string, mixed>
     */
    public static function described($descriptions, string $lang, array $fields): array
    {
        $row = $descriptions ? ($descriptions->firstWhere('lang', $lang) ?? $descriptions->first()) : null;
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = $row ? $row->{$field} : null;
        }
        return $out;
    }
}
