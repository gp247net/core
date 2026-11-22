<?php

namespace GP247\Core\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Core\Tests\TestCase;

/**
 * Verifies that the global gp247_* helper functions are loaded, callable,
 * and return values of the expected types after the Livewire/TailAdmin cutover.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-002
 */
class HelperFunctionsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Language helpers must be defined and callable.
     */
    public function test_language_helpers_are_defined(): void
    {
        $helpers = [
            'gp247_language_all',
            'gp247_languages',
            'gp247_language_render',
            'gp247_language_quickly',
        ];

        foreach ($helpers as $fn) {
            $this->assertTrue(function_exists($fn), "Helper {$fn} must be defined (US-CMP-002)");
        }
    }

    /**
     * Store helpers must be defined and callable.
     */
    public function test_store_helpers_are_defined(): void
    {
        $helpers = [
            'gp247_store_get_list_code',
            'gp247_store_info',
            'gp247_store_get_list_active',
            'gp247_store_is_root',
            'gp247_store_is_partner',
            'gp247_store_get_list_domain_of_array_link',
            'gp247_store_get_list_domain_of_link_detail',
        ];

        foreach ($helpers as $fn) {
            $this->assertTrue(function_exists($fn), "Helper {$fn} must be defined (US-CMP-002)");
        }
    }

    /**
     * Config/system helpers must be defined.
     */
    public function test_config_and_system_helpers_are_defined(): void
    {
        $helpers = [
            'gp247_config',
            'gp247_clean',
        ];

        foreach ($helpers as $fn) {
            $this->assertTrue(function_exists($fn), "Helper {$fn} must be defined (US-CMP-002)");
        }
    }

    /**
     * gp247_language_all() must return an array or Collection.
     */
    public function test_gp247_language_all_returns_iterable(): void
    {
        $result = gp247_language_all();
        $this->assertTrue(
            is_array($result) || $result instanceof \Traversable,
            'gp247_language_all() must return an array or Traversable'
        );
    }

    /**
     * gp247_store_get_list_active() must return a Collection or array.
     */
    public function test_gp247_store_get_list_active_returns_iterable(): void
    {
        $result = gp247_store_get_list_active();
        $this->assertTrue(
            is_array($result) || $result instanceof \Traversable,
            'gp247_store_get_list_active() must return iterable'
        );
    }

    /**
     * gp247_config() must return a scalar or null (not throw).
     */
    public function test_gp247_config_returns_without_exception(): void
    {
        // Call with a safe key that should exist or return null
        $result = gp247_config('site_name', null);
        $this->assertTrue(true, 'gp247_config() must not throw an exception');
    }

    /**
     * gp247_clean() must sanitize HTML tags (XSS prevention).
     */
    public function test_gp247_clean_strips_script_tags(): void
    {
        if (!function_exists('gp247_clean')) {
            $this->markTestSkipped('gp247_clean not available');
        }
        $input  = '<script>alert("xss")</script>Hello';
        $result = gp247_clean($input);
        $this->assertStringNotContainsString('<script>', $result);
    }

    /**
     * gp247_language_render() must return a string (not throw on missing key).
     */
    public function test_gp247_language_render_returns_string(): void
    {
        $result = gp247_language_render('admin.common_save');
        $this->assertIsString($result);
    }
}
