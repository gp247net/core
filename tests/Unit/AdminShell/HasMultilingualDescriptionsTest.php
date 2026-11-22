<?php

namespace GP247\Core\Tests\Unit\AdminShell;

use GP247\Core\AdminShell\Infrastructure\HasMultilingualDescriptions;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the reusable multilingual-descriptions trait (C0). Covers the
 * database-independent state logic: empty-state initialisation, populating from
 * an existing record's descriptions, and the first-language helper. The
 * DB-touching persist (saveDescriptions) is covered by the Category feature test.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-002
 * @aidlc-adr ADR-005, ADR-007
 */
class HasMultilingualDescriptionsTest extends TestCase
{
    /**
     * Build a stub component using the trait with fixed languages/fields and
     * public proxies for the protected helpers under test.
     *
     * @param array<int, string> $codes
     * @return object
     */
    private function stub(array $codes = ['en', 'vi']): object
    {
        return new class($codes) {
            use HasMultilingualDescriptions;

            /** @param array<int, string> $codes */
            public function __construct(private array $codes)
            {
            }

            protected function multilingualFields(): array
            {
                return ['title', 'keyword', 'description'];
            }

            protected function descriptionModelClass(): string
            {
                return 'StubDescriptionModel';
            }

            protected function descriptionForeignKey(): string
            {
                return 'category_id';
            }

            protected function descriptionLanguageCodes(): array
            {
                return $this->codes;
            }

            public function callInit(): void
            {
                $this->initDescriptions();
            }

            /** @param iterable<mixed> $descriptions */
            public function callFill(iterable $descriptions): void
            {
                $this->fillDescriptions($descriptions);
            }

            public function callFirstLanguage(): ?string
            {
                return $this->firstDescriptionLanguage();
            }
        };
    }

    public function test_init_creates_empty_state_for_every_language_and_field(): void
    {
        $stub = $this->stub(['en', 'vi']);
        $stub->callInit();

        $this->assertSame([
            'en' => ['title' => '', 'keyword' => '', 'description' => ''],
            'vi' => ['title' => '', 'keyword' => '', 'description' => ''],
        ], $stub->desc);
    }

    public function test_fill_maps_existing_descriptions_by_language(): void
    {
        $stub = $this->stub(['en', 'vi']);
        $stub->callFill([
            ['lang' => 'en', 'title' => 'Phones', 'keyword' => 'kw', 'description' => 'desc'],
            ['lang' => 'vi', 'title' => 'Điện thoại', 'keyword' => '', 'description' => ''],
        ]);

        $this->assertSame('Phones', $stub->desc['en']['title']);
        $this->assertSame('Điện thoại', $stub->desc['vi']['title']);
        $this->assertSame('kw', $stub->desc['en']['keyword']);
    }

    public function test_fill_defaults_missing_languages_to_empty(): void
    {
        $stub = $this->stub(['en', 'vi', 'fr']);
        $stub->callFill([
            ['lang' => 'en', 'title' => 'Only EN'],
        ]);

        $this->assertSame('Only EN', $stub->desc['en']['title']);
        $this->assertSame('', $stub->desc['vi']['title']);
        $this->assertSame('', $stub->desc['fr']['description']);
        $this->assertArrayHasKey('fr', $stub->desc);
    }

    public function test_fill_accepts_objects_with_lang_property(): void
    {
        $stub = $this->stub(['en']);
        $stub->callFill([
            (object) ['lang' => 'en', 'title' => 'Obj title', 'keyword' => 'k', 'description' => 'd'],
        ]);

        $this->assertSame('Obj title', $stub->desc['en']['title']);
    }

    public function test_first_language_returns_first_active_code(): void
    {
        $this->assertSame('en', $this->stub(['en', 'vi'])->callFirstLanguage());
        $this->assertNull($this->stub([])->callFirstLanguage());
    }
}
