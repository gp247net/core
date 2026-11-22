<?php

namespace GP247\Core\Tests\Unit\Plugin;

use GP247\Core\Tests\TestCase;

/**
 * Unit cases for the pure helpers behind the online extension library screen:
 * group selection -> raw code lists for the API,
 * raw code counts -> group counts, the core a non-compatible item needs, and the
 * page numbers of the pager.
 *
 * Boots the application only because core helpers are loaded by its service provider;
 * no database access.
 *
 * @aidlc-unit plugin-manager
 */
class OnlineCatalogHelpersTest extends TestCase
{
    private const NAMED = ['Payment', 'Shipping', 'Promotion', 'Marketing', 'Content', 'Business', 'Security'];

    public function test_named_groups_become_the_include_list(): void
    {
        $this->assertSame([['Payment', 'Security'], []], gp247_plugin_config_group_api_filter(['Payment', 'Security']));
    }

    public function test_other_becomes_an_exclude_list_of_every_named_group(): void
    {
        $this->assertSame([[], self::NAMED], gp247_plugin_config_group_api_filter(['Other']));
        $this->assertSame([['Shipping'], self::NAMED], gp247_plugin_config_group_api_filter(['Shipping', 'Other']));
    }

    public function test_unknown_or_duplicate_groups_are_dropped(): void
    {
        $this->assertSame([['Payment'], []], gp247_plugin_config_group_api_filter(['Payment', 'Payment', 'Bogus', ['x'], 5]));
        $this->assertSame([[], []], gp247_plugin_config_group_api_filter([]));
    }

    public function test_raw_code_counts_add_up_per_group(): void
    {
        $counts = gp247_plugin_config_group_counts([
            'Payment' => 3, 'Shipping' => '2', 'Other' => 1, 'Total' => 1, 'ProductFlashSale' => 2, '' => 4,
        ]);

        $this->assertSame(3, $counts['Payment']);
        $this->assertSame(2, $counts['Shipping']);
        $this->assertSame(8, $counts['Other'], 'Raw Other + unknown codes + empty code');
        $this->assertArrayNotHasKey('Security', $counts);
    }

    public function test_required_core_is_the_lowest_listed_core_above_the_running_one(): void
    {
        $this->assertSame('3.5.1', gp247_extension_required_core('3.5.1', '3.3'));
        $this->assertSame('3.10', gp247_extension_required_core('3.10', '3.9.2'));
        $this->assertSame('3.6', gp247_extension_required_core('3.0,3.6,4.0', '3.3'));
        $this->assertSame('4.0', gp247_extension_required_core(' 4.1 , 4.0 ', '3.12'));
    }

    public function test_required_core_is_empty_when_the_item_only_supports_older_cores(): void
    {
        $this->assertSame('', gp247_extension_required_core('2.1', '3.3'));
        $this->assertSame('', gp247_extension_required_core('', '3.3'));
        $this->assertSame('', gp247_extension_required_core('abc,<b>', '3.3'));
    }

    public function test_pages_show_first_last_and_a_window_around_the_current_page(): void
    {
        $this->assertSame([], gp247_extension_pagination_pages(1, 1));
        $this->assertSame([1, 2, 3], gp247_extension_pagination_pages(2, 3));
        $this->assertSame([1, '…', 5, 6, 7, 8, 9, '…', 15], gp247_extension_pagination_pages(7, 15));
        $this->assertSame([1, 2, 3, '…', 10], gp247_extension_pagination_pages(1, 10));
        $this->assertSame([1, '…', 8, 9, 10], gp247_extension_pagination_pages(10, 10));
    }

    public function test_a_single_hidden_page_is_shown_instead_of_an_ellipsis(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6, '…', 9], gp247_extension_pagination_pages(4, 9));
    }

    public function test_out_of_range_current_page_is_clamped(): void
    {
        $this->assertSame([1, 2, 3], gp247_extension_pagination_pages(99, 3));
        $this->assertSame([1, 2, 3], gp247_extension_pagination_pages(-4, 3));
    }
}
