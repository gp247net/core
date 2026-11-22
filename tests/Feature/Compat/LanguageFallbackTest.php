<?php

namespace GP247\Core\Tests\Feature\Compat;

use GP247\Core\Models\Languages;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use GP247\Core\Tests\TestCase;

/**
 * A string that is not translated in the language being viewed shows the
 * English text instead of its raw language code.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-language-fallback-en
 * @aidlc-adr compat-foundation_locale-data-files
 */
class LanguageFallbackTest extends TestCase
{
    use DatabaseTransactions;

    /** @var string Unique language code used by each test. */
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->code = 'test.fallback_'.Str::lower(Str::random(10));
    }

    /**
     * Insert one translation row and drop the per-request translation cache.
     */
    private function putText(string $location, string $text): void
    {
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX.'languages')->insert([
            'code' => $this->code, 'text' => $text, 'position' => 'test', 'location' => $location,
        ]);
        $this->flushTranslationCache();
    }

    /**
     * Languages::getListAll() memoises per location for the request; reset it
     * so rows written by the test are visible.
     */
    private function flushTranslationCache(): void
    {
        $prop = new \ReflectionProperty(Languages::class, 'getList');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    public function test_japanese_viewer_sees_the_japanese_text_when_it_exists(): void
    {
        $this->putText('en', 'Hello');
        $this->putText('ja', 'こんにちは');

        $this->assertSame('こんにちは', gp247_language_render($this->code, [], 'ja'));
    }

    public function test_japanese_viewer_sees_the_english_text_when_japanese_is_missing(): void
    {
        $this->putText('en', 'Hello :name');

        $this->assertSame('Hello Ken', gp247_language_render($this->code, ['name' => 'Ken'], 'ja'));
    }

    public function test_empty_japanese_text_counts_as_missing(): void
    {
        $this->putText('en', 'Hello');
        $this->putText('ja', '');

        $this->assertSame('Hello', gp247_language_render($this->code, [], 'ja'));
    }

    public function test_code_missing_everywhere_keeps_the_old_behaviour(): void
    {
        $this->flushTranslationCache();

        $this->assertSame($this->code, gp247_language_render($this->code, [], 'ja'));
    }

    public function test_quick_lookup_also_falls_back_to_english_before_the_default(): void
    {
        $this->putText('en', 'Hello');
        app()->setLocale('ja');

        $this->assertSame('Hello', gp247_language_quickly($this->code, 'default'));
        $this->assertSame('default', gp247_language_quickly($this->code.'_none', 'default'));
    }
}
