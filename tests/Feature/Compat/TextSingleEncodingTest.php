<?php

namespace GP247\Core\Tests\Feature\Compat;

use Illuminate\Support\Facades\Blade;
use GP247\Core\Tests\TestCase;

/**
 * Text stored HTML-encoded (gp247_clean on write) shows through {{ }} with ONE layer of
 * encoding: "Cron &amp; Queue" in the database reads "Cron & Queue" on the page, while raw
 * < > " ' & are still escaped and {!! !!} is untouched.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-text-single-encoding
 * @aidlc-adr compat-foundation_output-single-encoding
 */
class TextSingleEncodingTest extends TestCase
{
    /**
     * Render a one-line Blade template.
     *
     * @param string $template
     * @param string $value
     * @return string
     */
    private function render(string $template, string $value): string
    {
        return Blade::render($template, ['value' => $value]);
    }

    public function test_a_product_name_stored_with_an_encoded_ampersand_renders_as_a_single_ampersand(): void
    {
        $html = $this->render('<p>{{ $value }}</p>', 'Cron &amp; Queue (Free &amp; Pro)');

        $this->assertSame('<p>Cron &amp; Queue (Free &amp; Pro)</p>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function test_a_stored_encoded_script_tag_still_renders_as_text(): void
    {
        $html = $this->render('<p>{{ $value }}</p>', '&lt;script&gt;alert(1)&lt;/script&gt;');

        $this->assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_raw_html_characters_are_still_escaped(): void
    {
        $html = $this->render('<a title="{{ $value }}">x</a>', '<b>"x"</b>\' & y');

        $this->assertSame('<a title="&lt;b&gt;&quot;x&quot;&lt;/b&gt;&#039; &amp; y">x</a>', $html);
    }

    public function test_raw_output_is_unchanged(): void
    {
        $this->assertSame('<p>&amp;</p>', $this->render('<p>{!! $value !!}</p>', '&amp;'));
    }
}
