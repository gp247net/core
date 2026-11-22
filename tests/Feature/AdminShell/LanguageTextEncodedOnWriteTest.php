<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\LanguageStringManager;
use GP247\Core\Models\Languages;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * Language strings typed by an admin are stored HTML-encoded, both when an existing
 * string is edited and when a new one is added. Many views render labels with
 * {!! gp247_language_render() !!} to keep the HTML of the default labels; that is safe
 * only because nothing typed by a user reaches the table unencoded.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-language-text-encoded-on-write
 */
class LanguageTextEncodedOnWriteTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /** @var string[] Payloads that would run as script if stored raw. */
    private const PAYLOADS = ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>'];

    public function test_an_edited_string_is_stored_encoded_and_renders_as_text(): void
    {
        $this->asUser(slugs: ['admin_language']);

        foreach (self::PAYLOADS as $i => $payload) {
            $code = 'admin.zz_encoded_edit_' . $i;
            Livewire::test(LanguageStringManager::class)->set('lang', 'en')->call('saveString', $code, 'admin.zz', $payload);

            $stored = (string) Languages::where('code', $code)->where('location', 'en')->value('text');
            $this->assertStringNotContainsString('<', $stored, "stored raw: $stored");
            $this->assertSame(htmlspecialchars($payload, ENT_COMPAT, 'UTF-8'), $stored);
            $this->assertStringNotContainsString('<', (string) gp247_language_render($code, [], 'en'));
        }
    }

    public function test_a_new_string_is_stored_encoded(): void
    {
        $this->asUser(slugs: ['admin_language']);

        Livewire::test(LanguageStringManager::class)
            ->set('newForm.code', 'admin.zz_encoded_new')
            ->set('newForm.position', 'admin.zz')
            ->set('newForm.text', self::PAYLOADS[0])
            ->call('addString')
            ->assertHasNoErrors();

        $stored = (string) Languages::where('code', 'admin.zz_encoded_new')->value('text');
        $this->assertSame(htmlspecialchars(self::PAYLOADS[0], ENT_COMPAT, 'UTF-8'), $stored);
    }
}
