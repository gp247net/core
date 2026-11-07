<?php

if (!function_exists('gp247_search_keyword') && !in_array('gp247_search_keyword', config('gp247_functions_except', []))) {
    /**
     * Normalise a list search keyword before it reaches a query: strip whitespace
     * at both ends, including the full-width space (U+3000) Japanese input methods
     * produce and the non-breaking space (U+00A0) pasted text often carries.
     * Spaces between words are kept.
     *
     * WHY: the admin search box sends its raw value after a 300 ms debounce, so the
     * value itself must never be trimmed (the space between two words being typed
     * would be eaten); the query uses this normalised copy instead. Plugins call it
     * behind function_exists() so they keep working on an older core.
     *
     * @param mixed $keyword Raw keyword (non-strings become '').
     * @return string Keyword without surrounding whitespace; '' when only whitespace.
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-list-search-trim
     * @aidlc-adr ADR-005
     */
    function gp247_search_keyword($keyword): string
    {
        if (!is_string($keyword) || $keyword === '') {
            return '';
        }

        $trimmed = preg_replace('/^[\s\x{00A0}\x{3000}]+|[\s\x{00A0}\x{3000}]+$/u', '', $keyword);

        // WHY: preg_replace returns null on invalid UTF-8; fall back to the ASCII trim.
        return $trimmed ?? trim($keyword);
    }
}
