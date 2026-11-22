<?php

namespace GP247\Core\Tests\Unit\AdminShell;

use Carbon\CarbonImmutable;
use GP247\Core\AdminShell\Support\LivewireOperationLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for how LivewireOperationLog reduces component values before
 * they are written to `admin_log`: objects are described, never cast into
 * their internals, and recursion always ends.
 *
 * @aidlc-unit admin-shell-rbac
 */
class LivewireOperationLogScrubTest extends TestCase
{
    public function test_an_uploaded_file_becomes_its_original_name_and_size(): void
    {
        $file = UploadedFile::fake()->createWithContent('report.csv', 'a,b,c');

        $out = $this->scrub(['file' => $file]);

        $this->assertSame(['file' => ['_upload' => 'report.csv', 'size' => 5]], $out);
    }

    public function test_an_object_graph_with_a_cycle_ends_as_its_class_name(): void
    {
        $node = new ScrubCycleNode();
        $node->self = $node;

        $out = $this->scrub(['node' => $node]);

        $this->assertSame(['node' => ['_object' => ScrubCycleNode::class]], $out);
    }

    public function test_a_stdclass_cycle_stops_at_the_depth_limit(): void
    {
        $node = new \stdClass();
        $node->self = $node;

        $out = $this->scrub(['node' => $node]);

        $walk = $out['node'];
        $levels = 0;
        while (isset($walk['self'])) {
            $walk = $walk['self'];
            $levels++;
        }
        $this->assertSame(['_depth_limit' => true], $walk);
        $this->assertLessThanOrEqual(LivewireOperationLog::SCRUB_MAX_DEPTH, $levels);
    }

    public function test_arrayable_values_dates_and_enums_keep_their_meaning(): void
    {
        $out = $this->scrub([
            'rows' => new Collection([['name' => 'A', 'password' => 'x']]),
            'at' => CarbonImmutable::parse('2026-11-11 06:00:00', 'UTC'),
            'status' => ScrubStatus::Active,
            'kind' => ScrubKind::Plain,
        ]);

        $this->assertSame([['name' => 'A']], $out['rows'], 'toArray() is used and secrets are still dropped inside it');
        $this->assertSame('2026-11-11T06:00:00+00:00', $out['at']);
        $this->assertSame('active', $out['status']);
        $this->assertSame('Plain', $out['kind']);
    }

    public function test_scalars_and_secret_keys_behave_as_before(): void
    {
        $out = $this->scrub(['a' => 1, 'b' => null, 'nested' => ['client_secret' => 's', 'ok' => true]]);

        $this->assertSame(['a' => 1, 'b' => null, 'nested' => ['ok' => true]], $out);
    }

    /**
     * Run the protected scrub() with the default secret words.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function scrub(array $data): array
    {
        $hook = new LivewireOperationLog();
        $method = new \ReflectionMethod($hook, 'scrub');

        return $method->invoke($hook, $data, ['password', 'secret', 'token']);
    }
}

/** Test double: an object that references itself. */
class ScrubCycleNode
{
    /** @var mixed */
    public $self;

    /** @var array<int, int> */
    private array $payload = [1, 2, 3];
}

/** Test double: a backed enum. */
enum ScrubStatus: string
{
    case Active = 'active';
}

/** Test double: a pure enum. */
enum ScrubKind
{
    case Plain;
}
