<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\Tests\TestCase;

/**
 * `<x-gp247::searchable-select>` opens its option list only on what the admin does —
 * a click, focus or typing — never because the server pushed a new value. On a
 * two-column screen, editing another row or pressing "+ Create new" re-seeds every
 * select through `$wire.$watch`; the select must update its label and stay closed.
 *
 * PHP cannot run Alpine, so the contract is locked in the component source (Blade
 * comments stripped); the behaviour on a real screen is checked in the browser.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-004
 * @aidlc-adr ADR-admin-shell-rbac-ui-component-library
 */
class SearchableSelectServerSyncTest extends TestCase
{
    /**
     * Component source without Blade comments, so prose naming a token cannot satisfy
     * or trip an assertion.
     *
     * @return string
     */
    private function source(): string
    {
        $path = view('gp247-admin::components.searchable-select')->getPath();

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
    }

    public function test_a_value_pushed_by_the_server_does_not_open_the_option_list(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('$wire.$watch(model', $source, 'server changes must still reach the select');
        $this->assertDoesNotMatchRegularExpression(
            '/\$watch\(\s*[\'"]query[\'"]/',
            $source,
            'a watcher on `query` also fires when _seed() writes the label, which opens the list'
        );
        $this->assertMatchesRegularExpression('/_seed\(val\)\s*\{(?:(?!_openDropdown)[\s\S])*?\n\s*\},/', $source, '_seed() must not open the list');
    }

    public function test_typing_in_the_search_box_still_opens_the_option_list(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression('/onQueryInput\(\)\s*\{[\s\S]*?_openDropdown\(\)/', $source);
        // Both the single-value input and the multi-value tag input.
        $this->assertSame(2, preg_match_all('/x-model="query"\s+@input="onQueryInput"/', $source));
    }

    public function test_focusing_the_box_still_opens_the_option_list(): void
    {
        $source = $this->source();

        $this->assertSame(2, substr_count($source, '@focus="onInputFocus"'));
        $this->assertMatchesRegularExpression('/onInputFocus\(\)\s*\{\s*this\._openDropdown\(\);/', $source);
    }
}
