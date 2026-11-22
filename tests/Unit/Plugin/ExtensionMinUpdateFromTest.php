<?php

namespace GP247\Core\Tests\Unit\Plugin;

use Illuminate\Support\Facades\Log;
use GP247\Core\Tests\TestCase;

/**
 * Unit cases for gp247_extension_min_update_from(): key precedence, legacy fallback
 * with a deprecation warning, and empty / malformed values meaning "no floor".
 *
 * Boots the application only because core helpers are loaded by its service provider;
 * no database access.
 *
 * @aidlc-unit plugin-manager
 */
class ExtensionMinUpdateFromTest extends TestCase
{
    /**
     * The new key is returned trimmed and nothing is logged.
     */
    public function test_reads_min_update_from(): void
    {
        Log::spy();

        $this->assertSame('1.2', gp247_extension_min_update_from(['minUpdateFrom' => ' 1.2 ']));
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * The new key wins over the legacy key without a deprecation warning.
     */
    public function test_new_key_wins_when_both_are_present(): void
    {
        Log::spy();

        $this->assertSame('1.0', gp247_extension_min_update_from(['minUpdateFrom' => '1.0', 'requireUpdateFrom' => '2.0']));
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Only the legacy key: its value is used and a deprecation warning names the replacement.
     */
    public function test_falls_back_to_legacy_key_with_deprecation_warning(): void
    {
        Log::spy();

        $this->assertSame('2.0', gp247_extension_min_update_from(['requireUpdateFrom' => '2.0']));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'minUpdateFrom') && str_contains((string) $message, '4.0'))
            ->once();
    }

    /**
     * An empty new key falls through to the legacy key instead of disabling the floor.
     */
    public function test_blank_new_key_falls_back_to_legacy_key(): void
    {
        $this->assertSame('2.0', gp247_extension_min_update_from(['minUpdateFrom' => '  ', 'requireUpdateFrom' => '2.0']));
    }

    /**
     * No key, blank values or non-string values mean no floor.
     */
    public function test_missing_or_malformed_values_mean_no_floor(): void
    {
        $this->assertSame('', gp247_extension_min_update_from([]));
        $this->assertSame('', gp247_extension_min_update_from(['minUpdateFrom' => '']));
        $this->assertSame('', gp247_extension_min_update_from(['minUpdateFrom' => ['2.0'], 'requireUpdateFrom' => null]));
    }
}
