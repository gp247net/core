<?php

namespace GP247\Core\AdminShell\Infrastructure;

/**
 * Keyword search shared by admin list screens (ResourcePanel, DataTableComponent
 * and screens that build their own query): normalises the keyword at query time
 * and tells the admin which columns are searched.
 *
 * The using class owns `public string $keyword` (the raw value of the search box,
 * never rewritten here) and `searchable()` (the searched columns). It may override
 * `searchLabels()` to name those columns; without labels the screen keeps the
 * generic placeholder and shows no hint (third-party screens keep working).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-list-search-trim, US-admin-shell-list-search-scope-hint
 * @aidlc-adr ADR-005
 */
trait ListSearch
{
    /**
     * Language keys naming the searched columns, keyed by the column as listed in
     * searchable(). Optional: the default (none) keeps the generic placeholder.
     *
     * @return array<string, string> column => language key
     */
    protected function searchLabels(): array
    {
        return [];
    }

    /**
     * The keyword used by the query: whitespace stripped at both ends.
     *
     * @return string '' when the box is empty or holds only whitespace.
     */
    public function searchKeyword(): string
    {
        return gp247_search_keyword($this->keyword ?? '');
    }

    /**
     * Narrow a query to rows where any of the columns contains the keyword (bound
     * LIKE, OR'd). No-op when the keyword is blank or no column is given.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param array<int, string> $columns
     * @return void
     */
    protected function applyKeywordSearch($query, array $columns): void
    {
        $keyword = $this->searchKeyword();
        if ($keyword === '' || $columns === []) {
            return;
        }

        $needle = '%' . $keyword . '%';
        $query->where(function ($w) use ($columns, $needle): void {
            foreach (array_values($columns) as $i => $column) {
                $i === 0 ? $w->where($column, 'like', $needle) : $w->orWhere($column, 'like', $needle);
            }
        });
    }

    /**
     * Rendered labels of the searched columns, in searchable() order — or none
     * when any searched column lacks a label (a partial hint would mislead).
     *
     * @return array<int, string>
     */
    public function searchColumnLabels(): array
    {
        $keys = $this->searchLabels();
        $labels = [];
        foreach ($this->searchable() as $column) {
            if (!isset($keys[$column]) || $keys[$column] === '') {
                return [];
            }
            $labels[] = (string) gp247_language_render($keys[$column]);
        }

        return $labels;
    }

    /**
     * Placeholder of the search box: "Search by <columns>", or the generic one.
     *
     * @return string
     */
    public function searchPlaceholder(): string
    {
        $labels = $this->searchColumnLabels();

        return $labels === []
            ? (string) gp247_language_render('admin.search')
            : (string) gp247_language_render('admin.search.by', ['columns' => implode(', ', $labels)]);
    }

    /**
     * Hint line under the search box ("Searching in: a · b"), '' without labels.
     *
     * @return string
     */
    public function searchHint(): string
    {
        $labels = $this->searchColumnLabels();

        return $labels === []
            ? ''
            : (string) gp247_language_render('admin.search.in', ['columns' => implode(' · ', $labels)]);
    }

    /**
     * Message of an empty list: names the keyword and the columns when a search
     * emptied it, otherwise the usual "no records".
     *
     * @return string
     */
    public function searchEmptyMessage(): string
    {
        $keyword = $this->searchKeyword();
        $labels = $this->searchColumnLabels();
        if ($keyword === '' || $labels === []) {
            return (string) gp247_language_render('admin.no_records');
        }

        // WHY: columns first — the keyword is admin input and must not be scanned
        // for further :placeholders after it is inserted.
        return (string) gp247_language_render('admin.search.no_result', [
            'columns' => implode(', ', $labels),
            'keyword' => $keyword,
        ]);
    }
}
