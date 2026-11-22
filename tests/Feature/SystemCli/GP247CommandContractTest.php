<?php

namespace GP247\Core\Tests\Feature\SystemCli;

use GP247\Core\Console\GP247Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use GP247\Core\Tests\TestCase;

/**
 * Stub command that always succeeds with a small payload.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-001
 */
class Gp247ContractStubOk extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247test:ok';

    /** @var string */
    protected $description = 'contract stub (ok)';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        return $this->respondSuccess(['foo' => 'bar']);
    }
}

/**
 * Stub command that always fails with a coded error.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-001
 */
class Gp247ContractStubFail extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247test:fail';

    /** @var string */
    protected $description = 'contract stub (fail)';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        return $this->respondFailure('boom', 'it failed');
    }
}

/**
 * Stub command that throws, to prove the base wrapper converts uncaught
 * exceptions into a failure envelope.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-001
 */
class Gp247ContractStubThrow extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247test:throw';

    /** @var string */
    protected $description = 'contract stub (throw)';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        throw new \RuntimeException('kaboom');
    }
}

/**
 * Verifies US-CLI-001 output contract enforced by GP247Command: JSON envelope
 * shape, standard exit codes, exception handling, and the global --json option.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-001
 * @aidlc-adr system-cli_output-contract
 */
class GP247CommandContractTest extends TestCase
{
    /**
     * Register the stub commands before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::registerCommand(new Gp247ContractStubOk());
        Artisan::registerCommand(new Gp247ContractStubFail());
        Artisan::registerCommand(new Gp247ContractStubThrow());
    }

    /**
     * JSON success prints one valid envelope and returns exit code 0.
     *
     * @return void
     */
    public function test_json_success_envelope_and_exit_code(): void
    {
        $buffer = new BufferedOutput();
        $code = Artisan::call('gp247test:ok', ['--json' => true], $buffer);

        $this->assertSame(0, $code);
        $out = trim($buffer->fetch());
        $data = json_decode($out, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'STDOUT must be a single JSON object: ' . $out);
        $this->assertTrue($data['ok']);
        $this->assertSame('gp247test:ok', $data['command']);
        $this->assertSame('bar', $data['data']['foo']);
        $this->assertSame([], $data['warnings']);
        $this->assertNull($data['error']);
    }

    /**
     * JSON failure prints a coded error envelope and returns exit code 1.
     *
     * @return void
     */
    public function test_json_failure_envelope_and_exit_code(): void
    {
        $buffer = new BufferedOutput();
        $code = Artisan::call('gp247test:fail', ['--json' => true], $buffer);

        $this->assertSame(1, $code);
        $data = json_decode(trim($buffer->fetch()), true);
        $this->assertFalse($data['ok']);
        $this->assertSame('boom', $data['error']['code']);
        $this->assertSame('it failed', $data['error']['message']);
    }

    /**
     * An uncaught exception is turned into a failure envelope with exit code 1.
     *
     * @return void
     */
    public function test_uncaught_exception_becomes_failure_envelope(): void
    {
        $buffer = new BufferedOutput();
        $code = Artisan::call('gp247test:throw', ['--json' => true], $buffer);

        $this->assertSame(1, $code);
        $data = json_decode(trim($buffer->fetch()), true);
        $this->assertFalse($data['ok']);
        $this->assertSame('exception', $data['error']['code']);
        $this->assertStringContainsString('kaboom', $data['error']['message']);
    }

    /**
     * Human mode never emits the JSON envelope and still returns exit code 0.
     *
     * @return void
     */
    public function test_human_mode_prints_no_json_envelope(): void
    {
        $buffer = new BufferedOutput();
        $code = Artisan::call('gp247test:ok', [], $buffer);

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('"ok"', $buffer->fetch());
    }

    /**
     * Every registered gp247:* command exposes the global --json option.
     *
     * @return void
     */
    public function test_json_option_registered_on_every_gp247_command(): void
    {
        foreach (Artisan::all() as $name => $command) {
            if (strpos($name, 'gp247:') === 0) {
                $this->assertTrue(
                    $command->getDefinition()->hasOption('json'),
                    "{$name} must expose --json"
                );
            }
        }
    }
}
