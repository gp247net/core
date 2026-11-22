<?php

namespace GP247\Core\Tests\Unit\SystemCli;

use GP247\Core\Support\PreChange\PreChange;
use GP247\Core\Support\PreChange\PreChangeGate;
use GP247\Core\Support\PreChange\PreChangeResult;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Tests\TestCase;

/**
 * PreChangeGate: the core "before change" seam (ADR system-cli_pre-change-registry).
 *
 * @aidlc-unit system-cli
 */
#[Group('system-cli')]
class PreChangeGateTest extends TestCase
{
    private function change(): PreChange
    {
        return new PreChange('system.update', ['data' => 'all'], '3.10', '3.11');
    }

    public function test_no_registrant_proceeds_with_empty_outcome(): void
    {
        $outcome = (new PreChangeGate)->run($this->change(), []);

        $this->assertTrue($outcome->proceed());
        $this->assertSame([], $outcome->toArray());
    }

    public function test_registrant_receives_the_change_and_can_proceed_with_a_notice(): void
    {
        $seen = null;
        $outcome = (new PreChangeGate)->run($this->change(), [
            ['key' => 'backup', 'callback' => function (PreChange $c) use (&$seen) {
                $seen = $c;
                return PreChangeResult::proceed('Backup is not set up yet');
            }],
        ]);

        $this->assertTrue($outcome->proceed());
        $this->assertSame('system.update', $seen->operation);
        $this->assertSame(['data' => 'all'], $seen->scope);
        $this->assertSame(['Backup is not set up yet'], $outcome->notices());
        $this->assertSame('backup', $outcome->toArray()[0]['key']);
    }

    public function test_one_registrant_stopping_stops_the_change_with_its_reason(): void
    {
        $outcome = (new PreChangeGate)->run($this->change(), [
            ['key' => 'a', 'callback' => fn () => PreChangeResult::proceed()],
            ['key' => 'b', 'callback' => fn () => PreChangeResult::stop('Snapshot still running', 'job-1')],
        ]);

        $this->assertFalse($outcome->proceed());
        $this->assertSame(['Snapshot still running'], $outcome->reasons());
        $this->assertSame('job-1', $outcome->toArray()[1]['pending_job']);
    }

    public function test_a_failing_registrant_counts_as_a_stop(): void
    {
        $outcome = (new PreChangeGate)->run($this->change(), [
            ['key' => 'boom', 'callback' => function () {
                throw new \RuntimeException('disk full');
            }],
        ]);

        $this->assertFalse($outcome->proceed());
        $this->assertCount(1, $outcome->reasons());
        $this->assertSame('disk full', $outcome->toArray()[0]['error']);
    }

    public function test_a_registrant_returning_the_wrong_type_counts_as_a_stop(): void
    {
        $outcome = (new PreChangeGate)->run($this->change(), [
            ['key' => 'odd', 'callback' => fn () => true],
        ]);

        $this->assertFalse($outcome->proceed());
        $this->assertNotSame('', $outcome->toArray()[0]['error']);
    }

    public function test_non_callable_entries_are_skipped_and_plain_callables_accepted(): void
    {
        $outcome = (new PreChangeGate)->run($this->change(), [
            'not a callable',
            ['key' => 'missing-callback'],
            fn () => PreChangeResult::proceed(),
        ]);

        $this->assertTrue($outcome->proceed());
        $this->assertCount(1, $outcome->toArray());
    }

    public function test_registrants_default_to_the_core_config_key(): void
    {
        config(['gp247-config.core.pre_change' => [
            ['key' => 'cfg', 'callback' => fn () => PreChangeResult::stop('from config')],
        ]]);

        $outcome = (new PreChangeGate)->run($this->change());

        $this->assertSame(['from config'], $outcome->reasons());
    }

    public function test_helper_runs_the_gate(): void
    {
        config(['gp247-config.core.pre_change' => [fn (PreChange $c) => PreChangeResult::stop($c->operation)]]);

        $this->assertSame(['template.switch'], gp247_pre_change('template.switch')->reasons());
    }
}
