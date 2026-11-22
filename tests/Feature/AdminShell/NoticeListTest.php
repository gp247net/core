<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\NoticeList;
use GP247\Core\Models\AdminNotice;
use GP247\Core\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the admin notification list (US-AUI-008, ADR-001/005): notices are
 * scoped to the current admin (admin_id), bulk/per-row delete and mark-all-read
 * are Layer-2 authorized, and search filters by content. Mirrors the legacy
 * AdminNoticeController list while adding owner-scoping as defense-in-depth.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-008
 * @aidlc-adr ADR-001, ADR-005
 */
class NoticeListTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * Create a persisted admin user with the given primary-key id.
     *
     * @param string $id The uuid-style admin id.
     * @return AdminUser
     */
    private function makeAdmin(string $id): AdminUser
    {
        return AdminUser::create([
            'id' => $id,
            'username' => 'u_' . $id,
            'password' => bcrypt('secret'),
            'name' => 'Admin ' . $id,
            'email' => $id . '@test.local',
            'status' => 1,
        ]);
    }

    /**
     * Authenticate the given admin id on the `admin` guard (drives admin_id scope).
     *
     * @param string $id The admin id to act as.
     * @return AdminUser
     */
    private function loginAs(string $id): AdminUser
    {
        $user = $this->makeAdmin($id);
        $this->actingAs($user, 'admin');

        return $user;
    }

    /**
     * Seed one notice addressed to $adminId.
     *
     * @param string               $adminId Recipient admin id.
     * @param array<string, mixed> $attrs   Column overrides.
     * @return AdminNotice
     */
    private function seedNotice(string $adminId, array $attrs = []): AdminNotice
    {
        return AdminNotice::create(array_merge([
            'type' => 'gp247_order_created',
            'type_id' => '123',
            'status' => 0,
            'admin_id' => $adminId,
            'admin_created' => 'AU-SYSTEM',
            'content' => 'Order #123 created',
        ], $attrs));
    }

    public function test_lists_only_current_admin_notices(): void
    {
        $this->asUser(slugs: ['admin_notice']);
        $this->loginAs('AU-OWNER');
        $this->seedNotice('AU-OWNER', ['content' => 'MINE-NOTICE']);
        $this->seedNotice('AU-OTHER', ['content' => 'OTHER-NOTICE']);

        Livewire::test(NoticeList::class)
            ->assertSee('MINE-NOTICE')
            ->assertDontSee('OTHER-NOTICE');
    }

    public function test_mark_read_marks_current_admin_notices(): void
    {
        $this->asUser(slugs: ['admin_notice']);
        $this->loginAs('AU-OWNER');
        $mine = $this->seedNotice('AU-OWNER');
        $other = $this->seedNotice('AU-OTHER');

        Livewire::test(NoticeList::class)->call('markRead');

        $this->assertSame(1, (int) AdminNotice::find($mine->id)->status);
        // Other admin's notice is untouched.
        $this->assertSame(0, (int) AdminNotice::find($other->id)->status);
    }

    public function test_bulk_delete_removes_only_own_notices(): void
    {
        $this->asUser(slugs: ['admin_notice']);
        $this->loginAs('AU-OWNER');
        $mine = $this->seedNotice('AU-OWNER');
        $other = $this->seedNotice('AU-OTHER');

        Livewire::test(NoticeList::class)
            ->set('selected', [$mine->id, $other->id])
            ->call('bulkDelete');

        $this->assertFalse(AdminNotice::where('id', $mine->id)->exists());
        $this->assertTrue(AdminNotice::where('id', $other->id)->exists());
    }

    public function test_bulk_delete_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $this->loginAs('AU-OWNER');
        $mine = $this->seedNotice('AU-OWNER');

        $component = Livewire::test(NoticeList::class)->set('selected', [$mine->id]);

        $this->assertDenied(fn () => $component->call('bulkDelete'));
        $this->assertTrue(AdminNotice::where('id', $mine->id)->exists());
    }

    public function test_search_filters_by_content(): void
    {
        $this->asUser(slugs: ['admin_notice']);
        $this->loginAs('AU-OWNER');
        $this->seedNotice('AU-OWNER', ['content' => 'KEEPME']);
        $this->seedNotice('AU-OWNER', ['content' => 'DROPME']);

        Livewire::test(NoticeList::class)
            ->set('keyword', 'KEEPME')
            ->assertSee('KEEPME')
            ->assertDontSee('DROPME');
    }
}
