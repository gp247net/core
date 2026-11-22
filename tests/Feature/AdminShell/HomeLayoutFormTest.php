<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\HomeLayoutForm;
use GP247\Core\Models\AdminHome;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the home-layout block form (ADR-001/005): authorization, validation,
 * and persist with an extension derived from the view namespace.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-003
 * @aidlc-adr ADR-001, ADR-005
 */
class HomeLayoutFormTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    public function test_creates_block_and_derives_extension(): void
    {
        $this->asUser(slugs: ['admin_home_layout']);

        Livewire::test(HomeLayoutForm::class)
            ->set('form.view', 'gp247-admin::component.zz_test_block')
            ->set('form.size', 6)
            ->set('form.sort', 5)
            ->set('form.status', 1)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin_home_layout.index'));

        $block = AdminHome::where('view', 'gp247-admin::component.zz_test_block')->first();
        $this->assertNotNull($block);
        $this->assertSame('gp247-admin', $block->extension);
        $this->assertSame(6, (int) $block->size);
    }

    public function test_validates_size_range_and_required_view(): void
    {
        $this->asUser(slugs: ['admin_home_layout']);

        Livewire::test(HomeLayoutForm::class)
            ->set('form.view', '')
            ->set('form.size', 99)
            ->set('form.sort', -1)
            ->call('save')
            ->assertHasErrors(['form.view', 'form.size', 'form.sort']);
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(HomeLayoutForm::class)
            ->set('form.view', 'gp247-admin::component.zz_test_block')
            ->set('form.size', 6)
            ->set('form.sort', 1);

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(AdminHome::where('view', 'gp247-admin::component.zz_test_block')->exists());
    }
}
