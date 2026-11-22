<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use Illuminate\Support\Facades\Blade;
use GP247\Core\Tests\TestCase;

/**
 * Contract of the `wire:ignore` admin components with Livewire.
 *
 * A control wrapped in `wire:ignore` is never morphed by Livewire, so it must pull
 * server-side value changes itself. Two-panel screens edit records in place
 * (ResourcePanel `editRow`, `keepStateOnSave`), so a control that only seeds at init
 * keeps showing the previous record — and a rich editor that always writes back then
 * saves that stale value over the real one (RISK-TECH-rich-editor-stale-seed).
 *
 * PHP cannot run TinyMCE; these tests lock the JS contract in the component source
 * (the script lives in an `@assets` block, which only prints inside a Livewire
 * request, so it is read from the file rather than from Blade::render()). The
 * behaviour on a real screen is covered by the E2E journey `E2E-admin-rich-editor-reseed`.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-004
 * @aidlc-adr ADR-005
 */
class RichEditorSyncContractTest extends TestCase
{
    /**
     * Source of a core admin component with Blade comments stripped, so prose that
     * mentions a token does not satisfy (or trip) a contract assertion.
     *
     * @param string $name component name, e.g. "rich-editor"
     * @return string
     */
    private function componentSource(string $name): string
    {
        $path = view('gp247-admin::components.' . $name)->getPath();

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
    }

    public function test_rich_editor_reloads_its_content_when_the_bound_livewire_value_changes(): void
    {
        $html = $this->componentSource('rich-editor');

        $this->assertStringContainsString('$wire.$watch(model', $html, 'must follow server-side changes (editRow / cancel / save)');
        $this->assertMatchesRegularExpression('/\$wire\.\$watch\(model[\s\S]*?setContent\(/', $html, 'a watched change must reach the editor');
        $this->assertStringContainsString('hasFocus()', $html, 'never overwrite what the admin is typing');
        $this->assertStringContainsString('undoManager.clear()', $html, 'undo must not step back into the previous record');
    }

    public function test_rich_editor_writes_back_only_content_the_author_changed(): void
    {
        $html = $this->componentSource('rich-editor');

        // Blur and the submit-capture flush both gate on isDirty(); the flag is cleared
        // only when a value is loaded from the server.
        $this->assertSame(2, substr_count($html, 'isDirty()'), 'blur + submit flush must both gate on isDirty()');
        $this->assertStringContainsString('setDirty(false)', $html);
        $this->assertDoesNotMatchRegularExpression(
            "/editor\.on\('blur', \(\) => self\.\\\$wire\.set\(/",
            $html,
            'an unconditional blur write-back is what clobbered untouched content'
        );
    }

    public function test_rich_editor_passes_caller_attributes_to_its_wrapper(): void
    {
        $html = Blade::render('<x-gp247::rich-editor model="descriptions.en.content" label="Content" data-testid="admin-page-content-en" />');

        $this->assertStringContainsString('data-testid="admin-page-content-en"', $html);
        $this->assertStringContainsString('space-y-1', $html, 'wrapper keeps its own classes');
    }

    public function test_every_wire_ignore_component_in_core_follows_livewire_changes(): void
    {
        $dir = dirname(view('gp247-admin::components.rich-editor')->getPath());
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.blade.php') ?: [];
        $this->assertNotEmpty($files);

        $checked = [];
        foreach ($files as $file) {
            $code = $this->componentSource(basename($file, '.blade.php'));
            if (! str_contains($code, 'wire:ignore')) {
                continue;
            }
            $checked[] = basename($file);
            $this->assertStringContainsString(
                '$wire.$watch(',
                $code,
                basename($file) . ' is wire:ignore\'d but never follows server-side value changes'
            );
        }

        // Guard the scan itself: the three known wire:ignore controls must be found.
        foreach (['input.blade.php', 'rich-editor.blade.php', 'searchable-select.blade.php'] as $known) {
            $this->assertContains($known, $checked);
        }
    }
}
