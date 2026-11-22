<?php

namespace GP247\Core\Tests\Unit\SecurityHeaders;

use GP247\Core\SecurityHeaders\Domain\HstsSetting;
use GP247\Core\SecurityHeaders\Domain\InvalidSecuritySetting;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * HSTS value object: fixed max-age steps, preload needs subdomains + one year.
 *
 * @aidlc-unit security-headers
 * @aidlc-adr security-headers_config-hsts-and-kill-switch
 */
#[Group('security-headers')]
class HstsSettingTest extends TestCase
{
    public function test_header_value_has_only_max_age_by_default(): void
    {
        $this->assertSame('max-age=300', (new HstsSetting(300))->headerValue());
    }

    public function test_header_value_adds_subdomains_and_preload_when_chosen(): void
    {
        $this->assertSame(
            'max-age=31536000; includeSubDomains; preload',
            (new HstsSetting(31536000, true, true))->headerValue()
        );
        $this->assertSame('max-age=86400; includeSubDomains', (new HstsSetting(86400, true))->headerValue());
    }

    public function test_max_age_must_be_one_of_the_steps(): void
    {
        $this->expectException(InvalidSecuritySetting::class);
        new HstsSetting(1234);
    }

    public function test_preload_needs_subdomains(): void
    {
        $this->expectException(InvalidSecuritySetting::class);
        new HstsSetting(31536000, false, true);
    }

    public function test_preload_needs_at_least_one_year(): void
    {
        $this->expectException(InvalidSecuritySetting::class);
        new HstsSetting(2592000, true, true);
    }

    public function test_steps_start_short_and_end_at_two_years(): void
    {
        $steps = HstsSetting::STEPS;
        $this->assertSame(300, $steps[0]);
        $this->assertSame(63072000, $steps[count($steps) - 1]);
        $this->assertSame($steps, array_values(array_unique($steps)));
    }
}
