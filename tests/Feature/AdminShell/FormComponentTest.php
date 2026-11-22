<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\Feature\AdminShell\Support\TestFormComponent;
use GP247\Core\Tests\TestCase;

/**
 * Exercises the FormComponent base: Layer-2 authorization on save, validation,
 * and gp247_clean sanitization of the persisted payload (ADR-001 / ADR-005).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-003
 * @aidlc-story US-CMP-text-stored-as-typed
 * @aidlc-adr ADR-001, ADR-005
 */
class FormComponentTest extends TestCase
{
    use InteractsWithAdminUser;

    public function test_save_validates_required_fields(): void
    {
        $this->asUser(slugs: ['admin_test']);

        Livewire::test(TestFormComponent::class)
            ->set('form.name', '')
            ->call('save')
            ->assertHasErrors('form.name');
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(TestFormComponent::class)->set('form.name', 'Jane');

        $this->assertDenied(fn () => $component->call('save'));
    }

    public function test_save_stores_text_as_typed_and_leaves_escaping_to_the_output(): void
    {
        $this->asUser(slugs: ['admin_test']);

        Livewire::test(TestFormComponent::class)
            ->set('form.name', '<b>Jane</b>')
            ->call('save')
            ->assertHasNoErrors();

        // WHY: text is stored as typed (normalised: trimmed, valid UTF-8, no control characters);
        // views escape it on output (US-CMP-text-stored-as-typed).
        $component = Livewire::test(TestFormComponent::class)
            ->set('form.name', '  <b>Jane</b> & Co  ')
            ->call('save');

        $this->assertSame(
            '<b>Jane</b> & Co',
            $component->get('persisted')['name'],
        );
    }
}
