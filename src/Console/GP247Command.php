<?php

namespace GP247\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Throwable;

/**
 * Base command shared by every GP247 Artisan command (`gp247:*`).
 *
 * It enforces one output contract across the whole CLI so scripts, CI/CD,
 * Docker and cron can rely on it (ADR system-cli_output-contract):
 *
 *  - A global `--json` flag. In JSON mode the command prints exactly one
 *    envelope object to STDOUT and routes every human/progress/warning line
 *    (including info()/line()/comment()/warn() and nested command output) to
 *    STDERR, so `php artisan gp247:info --json | jq` stays clean.
 *  - A stable envelope shape: {ok, command, data, warnings, error}.
 *  - Standard exit codes: 0 on success, non-zero on failure — subclasses
 *    return the int from respondSuccess()/respondFailure().
 *  - Consistent failure logging through gp247_report().
 *
 * Subclasses implement handleGp247() instead of handle(); this base wraps it
 * so any uncaught Throwable becomes a logged failure envelope with a non-zero
 * exit code, never a stack trace leaking onto STDOUT in JSON mode.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-001
 * @aidlc-adr system-cli_output-contract
 */
abstract class GP247Command extends Command
{
    /**
     * Warnings accumulated during the run, surfaced in the JSON envelope and
     * echoed to STDERR in human mode.
     *
     * @var array<int, string>
     */
    protected array $gp247Warnings = [];

    /**
     * Append the global `--json` option to whatever signature the subclass
     * declared, without forcing each command to repeat it.
     *
     * WHY: Laravel parses $signature in the constructor, so we inject the
     * option here before the parent constructor runs rather than editing every
     * subclass signature string.
     */
    public function __construct()
    {
        if (strpos($this->signature, '--json') === false) {
            $this->signature = rtrim($this->signature) . ' {--json : Output a machine-readable JSON envelope}';
        }
        parent::__construct();
    }

    /**
     * The actual command body. Implement this instead of handle().
     *
     * Return an int exit code — normally the value returned by
     * respondSuccess() or respondFailure().
     *
     * @return int Exit code (Command::SUCCESS or Command::FAILURE).
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-001
     */
    abstract protected function handleGp247(): int;

    /**
     * Execute the console command, wrapping handleGp247() so failures always
     * become a logged, correctly-shaped result with a non-zero exit code.
     *
     * @return int Exit code.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-001
     */
    public function handle(): int
    {
        try {
            return $this->handleGp247();
        } catch (Throwable $e) {
            // WHY: a single choke point guarantees no command can crash with a
            // raw stack trace on STDOUT (which would corrupt JSON pipelines);
            // every failure is logged and shaped identically.
            if (function_exists('gp247_report')) {
                gp247_report($e->getMessage());
            }
            return $this->respondFailure('exception', $e->getMessage());
        }
    }

    /**
     * Whether the caller asked for machine-readable output.
     *
     * @return bool True when `--json` was passed.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-001
     */
    protected function isJson(): bool
    {
        // WHY: guard hasOption — some early boot paths construct the command
        // without the parsed input definition available.
        return $this->input !== null && $this->input->hasOption('json') && (bool) $this->option('json');
    }

    /**
     * Record a warning. Shown under `warnings` in the JSON envelope, and
     * written to STDERR immediately in human mode.
     *
     * @param string $message Human-readable warning text.
     * @return void
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-001
     */
    protected function addWarning(string $message): void
    {
        $this->gp247Warnings[] = $message;
        if (!$this->isJson()) {
            $this->writeStderr('<comment>' . $message . '</comment>');
        }
    }

    /**
     * Run a nested Artisan command without polluting STDOUT in JSON mode.
     *
     * In human mode this behaves like $this->call(). In JSON mode the nested
     * command's output is captured and forwarded to STDERR, keeping the single
     * envelope on STDOUT intact.
     *
     * @param string               $command   The command name (e.g. "migrate").
     * @param array<string, mixed> $arguments Command arguments/options.
     * @return int The nested command's exit code.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-003
     */
    protected function runArtisan(string $command, array $arguments = []): int
    {
        if (!$this->isJson()) {
            return $this->call($command, $arguments);
        }
        $buffer = new BufferedOutput();
        $code = Artisan::call($command, $arguments, $buffer);
        $text = trim($buffer->fetch());
        if ($text !== '') {
            $this->writeStderr($text);
        }
        return $code;
    }

    /**
     * Emit a success result and return the success exit code.
     *
     * In JSON mode this prints the single envelope to STDOUT. In human mode the
     * subclass is expected to have already printed its own text; $data is only
     * used for the JSON envelope.
     *
     * @param array<string, mixed> $data Payload for machine consumers.
     * @return int Command::SUCCESS.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-001
     */
    protected function respondSuccess(array $data = []): int
    {
        if ($this->isJson()) {
            $this->writeJson([
                'ok'       => true,
                'command'  => $this->getName(),
                'data'     => (object) $data,
                'warnings' => $this->gp247Warnings,
                'error'    => null,
            ]);
        }
        return Command::SUCCESS;
    }

    /**
     * Emit a failure result and return the failure exit code.
     *
     * @param string               $code A short, stable machine token (e.g. "not_writable").
     * @param string               $msg  Human-readable error message.
     * @param array<string, mixed> $data Optional extra payload for machine consumers.
     * @return int Command::FAILURE.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-001
     */
    protected function respondFailure(string $code, string $msg, array $data = []): int
    {
        if ($this->isJson()) {
            $this->writeJson([
                'ok'       => false,
                'command'  => $this->getName(),
                'data'     => (object) $data,
                'warnings' => $this->gp247Warnings,
                'error'    => ['code' => $code, 'message' => $msg],
            ]);
        } else {
            $this->writeStderr('<error>' . $msg . '</error>');
        }
        return Command::FAILURE;
    }

    // ---------------------------------------------------------------------
    // IO overrides: in JSON mode every human line goes to STDERR so STDOUT
    // carries only the envelope. In human mode behaviour is unchanged.
    // ---------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public function line($string, $style = null, $verbosity = null)
    {
        if ($this->isJson()) {
            $this->writeStderr($style ? "<$style>$string</$style>" : $string);
            return;
        }
        parent::line($string, $style, $verbosity);
    }

    /**
     * {@inheritdoc}
     */
    public function info($string, $verbosity = null)
    {
        if ($this->isJson()) {
            $this->writeStderr("<info>$string</info>");
            return;
        }
        parent::info($string, $verbosity);
    }

    /**
     * {@inheritdoc}
     */
    public function comment($string, $verbosity = null)
    {
        if ($this->isJson()) {
            $this->writeStderr("<comment>$string</comment>");
            return;
        }
        parent::comment($string, $verbosity);
    }

    /**
     * {@inheritdoc}
     */
    public function warn($string, $verbosity = null)
    {
        if ($this->isJson()) {
            $this->writeStderr("<comment>$string</comment>");
            return;
        }
        parent::warn($string, $verbosity);
    }

    /**
     * Write the one-and-only JSON envelope to STDOUT.
     *
     * @param array<string, mixed> $envelope The envelope to encode.
     * @return void
     */
    private function writeJson(array $envelope): void
    {
        $this->output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Write a line to STDERR when the console supports a separate error stream,
     * otherwise fall back to the normal output.
     *
     * WHY: keeping progress/warnings off STDOUT is what makes `--json | jq`
     * reliable; but under a buffered/test output there is no error stream, so
     * we degrade gracefully.
     *
     * @param string $line Line to write (may contain Symfony style tags).
     * @return void
     */
    protected function writeStderr(string $line): void
    {
        $out = $this->output->getOutput();
        if ($out instanceof ConsoleOutputInterface) {
            $out->getErrorOutput()->writeln($line);
            return;
        }
        $this->output->writeln($line);
    }
}
