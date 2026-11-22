<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\AdminLogList;
use GP247\Core\Models\AdminLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the admin operation-log list (ADR-001/005): listing, search, delete,
 * deny. Read-only screen — rows expose delete only, no edit.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002
 * @aidlc-adr ADR-001, ADR-005
 */
class AdminLogListTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * Seed a single admin-log row.
     *
     * @param string $path Request path recorded by the log.
     * @param string $ip   Source IP recorded by the log.
     * @return AdminLog
     */
    private function seedLog(string $path = '/zz/path', string $ip = '10.0.0.9'): AdminLog
    {
        return AdminLog::create([
            'user_id' => 'AU-AAAAA',
            'method' => 'POST',
            'path' => $path,
            'ip' => $ip,
            'user_agent' => 'PHPUnit',
            'input' => '{}',
        ]);
    }

    public function test_lists_logs(): void
    {
        $this->asUser(slugs: ['admin_log']);
        $log = $this->seedLog();

        Livewire::test(AdminLogList::class)->assertSee($log->path);
    }

    public function test_search_filters_by_ip(): void
    {
        $this->asUser(slugs: ['admin_log']);
        $hit = $this->seedLog('/keep', '10.0.0.1');
        $miss = $this->seedLog('/drop', '10.0.0.2');

        Livewire::test(AdminLogList::class)
            ->set('keyword', '10.0.0.1')
            ->assertSee($hit->path)
            ->assertDontSee($miss->path);
    }

    public function test_single_delete_removes_log(): void
    {
        $this->asUser(slugs: ['admin_log']);
        $log = $this->seedLog();

        Livewire::test(AdminLogList::class)->call('delete', $log->id);

        $this->assertFalse(AdminLog::where('id', $log->id)->exists());
    }

    public function test_bulk_delete_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $log = $this->seedLog();

        $component = Livewire::test(AdminLogList::class)->set('selected', [$log->id]);

        $this->assertDenied(fn () => $component->call('bulkDelete'));
        $this->assertTrue(AdminLog::where('id', $log->id)->exists());
    }
}
