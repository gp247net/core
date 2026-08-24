<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrate a full GP247 install in the correct order. This is the platform's
 * common install entry point: it auto-detects which packages are present (core
 * is always installed; front/shop are installed only when their install command
 * is registered) and installs them core -> front -> shop -> optional sample.
 *
 * Registered in the bootstrap tier (CoreServiceProvider::initial()), so it is
 * available the moment the package is installed — before gp247:core-install has
 * run and regardless of whether front/shop are present-but-not-installed.
 *
 * Safety: front-install / shop-install DROP and recreate their tables (they call
 * *-uninstall first), so running this on a live site destroys front/shop data.
 * By default the command therefore REQUIRES confirmation: it refuses to run in a
 * non-interactive / --json context without --force, and prompts (defaulting to
 * "no") in an interactive terminal.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-003
 * @aidlc-adr system-cli_command-registration-tiers
 */
class InstallAll extends GP247Command
{
    /**
     * The name and signature of the console command.
     *
     * WHY: package selection is fully auto-detected (see detectSteps() + ADR
     * system-cli_command-registration-tiers), so there are no --with-* flags to
     * choose front/shop — they are installed whenever their command is present.
     *
     * @var string
     */
    protected $signature = 'gp247:install
        {--sample : Seed demo shop data (dev only, wipes shop data)}
        {--force=0 : Unattended install (skip confirmation — DESTROYS existing front/shop data)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install GP247 end-to-end (auto-detects core + front + shop)';

    /**
     * Orchestrate the install: detect packages, confirm, then run each step.
     *
     * @return int Exit code (Command::SUCCESS / Command::FAILURE).
     */
    protected function handleGp247(): int
    {
        $force = (bool) $this->option('force');

        $steps = $this->detectSteps();
        $alreadyInstalled = Storage::disk('local')->exists('gp247-installed.txt');

        if (!$force) {
            $refusal = $this->guardConfirmation($steps, $alreadyInstalled);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return $this->runSteps($steps);
    }

    /**
     * Build the ordered list of install steps from the commands that are actually
     * registered. Composer guarantees shop implies front implies core, so the
     * order core -> front -> shop -> sample is always valid.
     *
     * @return array<int, string> Ordered Artisan command names.
     */
    private function detectSteps(): array
    {
        $app = $this->getApplication();

        $steps = ['gp247:core-install'];
        if ($app !== null && $app->has('gp247:front-install')) {
            $steps[] = 'gp247:front-install';
        }
        if ($app !== null && $app->has('gp247:shop-install')) {
            $steps[] = 'gp247:shop-install';
        }
        if ($this->option('sample') && $app !== null && $app->has('gp247:shop-sample')) {
            $steps[] = 'gp247:shop-sample';
        }

        return $steps;
    }

    /**
     * Enforce the default confirmation gate before any destructive step runs.
     *
     * @param array<int, string> $steps            The detected install steps.
     * @param bool                $alreadyInstalled Whether the installed marker exists.
     * @return int|null A failure/cancel exit code to return immediately, or null to proceed.
     */
    private function guardConfirmation(array $steps, bool $alreadyInstalled): ?int
    {
        // WHY: a non-interactive / JSON caller cannot answer a prompt, and this
        // chain can wipe live data, so we refuse instead of silently proceeding.
        // --force=1 is the explicit unattended path.
        if ($this->isJson() || !$this->input->isInteractive()) {
            return $this->respondFailure(
                'confirmation_required',
                'Refusing to install without confirmation. Pass --force=1 for unattended install.',
                ['detected' => $steps, 'alreadyInstalled' => $alreadyInstalled]
            );
        }

        $this->line('About to run: ' . implode(' -> ', $steps));

        $destructive = array_intersect($steps, ['gp247:front-install', 'gp247:shop-install', 'gp247:shop-sample']);
        if (!empty($destructive)) {
            $this->warn('WARNING: front/shop install will DROP & recreate their tables — existing data WILL BE LOST.');
        }
        if ($alreadyInstalled) {
            $this->warn('WARNING: GP247 is ALREADY installed — this will RE-INSTALL and overwrite existing data.');
        }
        if ($this->option('sample')) {
            $this->warn('WARNING: --sample will wipe shop data and seed demo content.');
        }

        if (!$this->confirm('Proceed with installation?', false)) {
            $this->info('Installation canceled');
            return $this->respondSuccess(['installed' => false, 'canceled' => true]);
        }

        return null;
    }

    /**
     * Run each install step in order, aborting on the first failure.
     *
     * @param array<int, string> $steps Ordered Artisan command names.
     * @return int Exit code.
     */
    private function runSteps(array $steps): int
    {
        $done = [];
        foreach ($steps as $command) {
            $this->info('==> ' . $command);

            // WHY: confirmation was already obtained here, so force core-install
            // to skip its own prompt / already-installed refusal (avoids a nested
            // second prompt). front/shop-install expose no --force option.
            $args = $command === 'gp247:core-install' ? ['--force' => 1] : [];

            $code = $this->runArtisan($command, $args);
            if ($code !== Command::SUCCESS) {
                return $this->respondFailure('step_failed', 'Step failed: ' . $command, [
                    'completed' => $done,
                    'failed'    => $command,
                ]);
            }
            $done[] = $command;
        }

        return $this->respondSuccess(['completed' => $done]);
    }
}
