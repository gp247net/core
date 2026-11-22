<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\AdminShell\Support\LivewireOperationLog;
use GP247\Core\Models\AdminLog;
use GP247\Core\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithFileUploads;
use GP247\Core\Tests\Feature\AdminShell\Support\InteractsWithAdminUser;
use GP247\Core\Tests\TestCase;

/**
 * Every Livewire action an administrator performs on an admin screen must land
 * in the operation log without any component author having to remember it: the
 * route middleware never sees `livewire/update`, so the log is written from
 * Livewire's own `call` lifecycle (LivewireOperationLog).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-admin-shell-livewire-operation-log
 */
class LivewireOperationLogTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gp247-config.admin.admin_log' => 1]);
        config(['gp247-config.admin.admin_log_livewire_except' => LivewireOperationLog::DEFAULT_EXCEPT]);
    }

    public function test_every_livewire_action_on_an_admin_screen_is_written_to_the_operation_log(): void
    {
        $admin = $this->signInRealAdmin();
        $before = AdminLog::count();

        Livewire::test(OperationLogProbe::class)
            ->set('note', 'hello')
            ->set('secret_token', 'hunter2')
            ->call('save', 'plan-1', ['fee' => 5]);

        $this->assertSame($before + 1, AdminLog::count(), 'exactly one row per action — property syncs are not operations');
        $row = AdminLog::orderByDesc('id')->first();
        $this->assertSame(LivewireOperationLog::METHOD, $row->method);
        $this->assertSame($admin->id, $row->user_id);
        $this->assertStringStartsWith('livewire/', $row->path, 'without a Referer the component name identifies the screen');

        $input = json_decode(html_entity_decode($row->input, ENT_QUOTES), true);
        $this->assertSame('save', $input['method']);
        $this->assertSame(['plan-1', ['fee' => 5]], $input['params']);
        $this->assertSame('hello', $input['data']['note'], 'the state the admin edited is kept');
        $this->assertArrayNotHasKey('secret_token', $input['data'], 'secret-looking keys never reach the log');
        $this->assertSame(OperationLogProbe::class, $input['class']);
    }

    public function test_reads_and_livewire_plumbing_are_not_operations(): void
    {
        $this->signInRealAdmin();
        $before = AdminLog::count();

        Livewire::test(OperationLogProbe::class)->call('$refresh');
        $this->assertSame($before, AdminLog::count(), '$refresh is a read');

        config(['gp247-config.admin.admin_log_livewire_except' => LivewireOperationLog::DEFAULT_EXCEPT.',peek']);
        Livewire::test(OperationLogProbe::class)->call('peek');
        $this->assertSame($before, AdminLog::count(), 'the marketplace can silence its own read-only actions');

        config(['gp247-config.admin.admin_log' => 0]);
        Livewire::test(OperationLogProbe::class)->call('save', 'x');
        $this->assertSame($before, AdminLog::count(), 'the master switch still rules');
    }

    public function test_nothing_is_logged_without_an_admin_session_or_outside_admin_screens(): void
    {
        $this->asUser(administrator: true); // contract only — no admin guard session
        $before = AdminLog::count();
        Livewire::test(OperationLogProbe::class)->call('save', 'x');
        $this->assertSame($before, AdminLog::count(), 'no signed-in admin, no row');

        $this->signInRealAdmin();
        Livewire::test(PlainStorefrontProbe::class)->call('save');
        $this->assertSame($before, AdminLog::count(), 'a component that is neither an admin component nor fired from an admin screen is not an admin operation');
    }

    public function test_a_failed_action_leaves_no_trace_of_something_that_did_not_happen(): void
    {
        $this->signInRealAdmin();
        $before = AdminLog::count();

        try {
            Livewire::test(OperationLogProbe::class)->call('boom');
            $this->fail('boom must throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($before, AdminLog::count());
    }

    public function test_an_oversized_state_is_trimmed_but_the_action_is_still_logged(): void
    {
        $this->signInRealAdmin();

        Livewire::test(OperationLogProbe::class)
            ->set('note', str_repeat('x', 70000))
            ->call('save', 'big');

        $row = AdminLog::orderByDesc('id')->first();
        $this->assertLessThanOrEqual(65535, strlen($row->input));
        $input = json_decode(html_entity_decode($row->input, ENT_QUOTES), true);
        $this->assertSame('save', $input['method']);
        $this->assertSame(['big'], $input['params']);
        $this->assertTrue($input['data']['_truncated']);
    }

    public function test_the_fields_the_admin_changed_in_the_same_request_are_recorded(): void
    {
        $this->signInRealAdmin();

        // wire:model values are flushed together with the click: one request
        // carrying both the property updates and the method call.
        Livewire::test(OperationLogProbe::class)
            ->update([['method' => 'save', 'params' => ['plan-1'], 'path' => '']], ['note' => 'edited', 'secret_token' => 'hunter2']);

        $row = AdminLog::orderByDesc('id')->first();
        $input = json_decode(html_entity_decode($row->input, ENT_QUOTES), true);
        $this->assertSame('save', $input['method']);
        $this->assertSame(['note' => 'edited'], $input['changed'], 'the touched fields are listed, secrets excluded');
    }

    public function test_the_log_list_shows_what_each_row_did_not_only_where(): void
    {
        $this->signInRealAdmin();
        $this->asUser(slugs: ['admin_log']);
        $base = ['user_id' => 'AU-AAAAA', 'ip' => '127.0.0.1', 'user_agent' => 'probe'];
        $lw = AdminLog::create($base + ['path' => 'gp247_admin/MultiVendor/plan', 'method' => LivewireOperationLog::METHOD,
            'input' => json_encode(['component' => 'vendor-plan-manager', 'class' => 'X', 'method' => 'save', 'params' => ['default'], 'changed' => ['form.name' => 'Gói mặc định', 'form.fee_amount' => '5'], 'data' => ['editId' => 'default']])]);
        $post = AdminLog::create($base + ['path' => 'gp247_admin/config', 'method' => 'POST', 'input' => json_encode(['_token' => 't', 'site_name' => 'S-Cart'])]);
        $get = AdminLog::create($base + ['path' => 'gp247_admin/log', 'method' => 'GET', 'input' => '[]']);

        $html = Livewire::test(\GP247\Core\AdminShell\Http\Livewire\AdminLogList::class)->html();

        $this->assertStringContainsString(gp247_language_render('admin.log.detail'), $html, 'the column exists');
        $this->assertStringContainsString('data-testid="admin-log-detail-'.$lw->id.'"', $html);
        $this->assertMatchesRegularExpression('/admin-log-detail-'.$lw->id.'".*?>save<.*?form\.name.*?form\.fee_amount/s', $html, 'a Livewire row names the action and the fields it touched');
        $this->assertMatchesRegularExpression('/admin-log-detail-'.$post->id.'".*?site_name/s', $html, 'a classic POST lists its input keys');
        $this->assertStringNotContainsString('_token', $html, 'the CSRF token is neither a field nor part of the full input');
        // Livewire wraps @if output in <!--[if BLOCK]> markers; skip them.
        $this->assertMatchesRegularExpression('/admin-log-detail-'.$get->id.'"[^>]*>\s*(<![^>]*>\s*)*<span[^>]*>—</su', $html, 'a page view has nothing to show');
        $this->assertStringContainsString(gp247_language_render('admin.log.view_input'), $html, 'the full input can be opened');
    }

    public function test_an_uploaded_file_is_logged_as_its_name_and_size_without_breaking_the_upload(): void
    {
        $this->signInRealAdmin();
        $lastId = (int) AdminLog::max('id');
        $content = "sku,name\nCSV-BODY-MARKER,Widget\n";

        // Uploading (Livewire's _startUpload/_finishUpload) and then saving are
        // both admin actions on a screen with WithFileUploads.
        Livewire::test(UploadLogProbe::class)
            ->set('file', UploadedFile::fake()->createWithContent('products.csv', $content))
            ->call('save')
            ->assertSet('saved', true);

        $rows = AdminLog::where('id', '>', $lastId)->where('method', LivewireOperationLog::METHOD)->orderBy('id')->get();
        $this->assertNotEmpty($rows, 'the upload screen is still audited');
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('CSV-BODY-MARKER', html_entity_decode($row->input, ENT_QUOTES), 'the file content never reaches the log');
        }

        $input = json_decode(html_entity_decode($rows->last()->input, ENT_QUOTES), true);
        $this->assertSame('save', $input['method']);
        $this->assertSame(['_upload' => 'products.csv', 'size' => strlen($content)], $input['data']['file'], 'the file is described by its original name and size');
        $this->assertSame('note kept', $input['data']['note'], 'the rest of the state is still logged');
    }

    public function test_deeply_nested_state_is_cut_at_the_depth_limit_and_secrets_are_still_dropped(): void
    {
        $this->signInRealAdmin();

        $tree = ['leaf' => 'bottom'];
        for ($i = 0; $i < 40; $i++) {
            $tree = ['level' => $i, 'api_key' => 'k-'.$i, 'child' => $tree];
        }

        Livewire::test(OperationLogProbe::class)
            ->set('saved', $tree)
            ->call('peek');

        $row = AdminLog::orderByDesc('id')->first();
        $raw = html_entity_decode($row->input, ENT_QUOTES);
        $input = json_decode($raw, true);
        $this->assertSame('peek', $input['method']);

        $node = $input['data']['saved'];
        $depth = 0;
        while (is_array($node) && !isset($node['_depth_limit'])) {
            $this->assertArrayNotHasKey('api_key', $node, 'secrets are dropped at every depth that is still written');
            $this->assertArrayHasKey('child', $node);
            $node = $node['child'];
            $depth++;
        }
        $this->assertSame(['_depth_limit' => true], $node, 'past the depth limit the branch is replaced by a marker');
        $this->assertGreaterThan(3, $depth, 'ordinary nesting (form.items.0.field) is kept');
        $this->assertLessThan(40, $depth, 'the walk stops before the end of the tree');
        $this->assertStringNotContainsString('bottom', $raw);
    }

    /** A real admin on the `admin` guard (admin()->user()) plus the authorization contract. */
    private function signInRealAdmin(): AdminUser
    {
        $suffix = substr(uniqid(), -4);
        $user = AdminUser::create([
            'id' => 'AU-LW'.$suffix,
            'username' => 'lwlog_'.$suffix,
            'password' => bcrypt('secret-'.$suffix),
            'name' => 'Livewire Log Probe',
            'email' => 'lwlog-'.$suffix.'@test.local',
            'status' => 1,
        ]);
        $this->actingAs($user, 'admin');
        $this->asUser(administrator: true);

        return $user;
    }
}

/**
 * Minimal admin component (test double): one write action, one read, one failure.
 */
class OperationLogProbe extends GP247AdminComponent
{
    protected ?string $permission = 'admin_log';

    public string $note = '';

    public string $secret_token = '';

    /** @var array<int, mixed> */
    public array $saved = [];

    public function save(string $id, array $payload = []): void
    {
        $this->saved = [$id, $payload];
    }

    public function peek(): void
    {
    }

    public function boom(): void
    {
        throw new \RuntimeException('boom');
    }

    public function render()
    {
        return '<div>probe {{ $note === "" ? "" : "noted" }}</div>';
    }
}

/**
 * A storefront-style component: not an admin component, no admin Referer.
 */
class PlainStorefrontProbe extends Component
{
    public function save(): void
    {
    }

    public function render()
    {
        return '<div>plain</div>';
    }
}

/**
 * An admin screen with a file upload (WithFileUploads): once the browser has
 * uploaded, the state holds a TemporaryUploadedFile.
 */
class UploadLogProbe extends GP247AdminComponent
{
    use WithFileUploads;

    protected ?string $permission = 'admin_log';

    /** @var mixed */
    public $file;

    public string $note = 'note kept';

    public bool $saved = false;

    public function save(): void
    {
        $this->saved = true;
    }

    public function render()
    {
        return '<div>upload probe</div>';
    }
}
