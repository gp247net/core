<?php

namespace GP247\Core\Commands;

use Illuminate\Console\Command;
use Throwable;
use GP247\Core\Database\Seeders\DataLanguageSeeder;

/**
 * Refresh GP247 language rows for core/front/shop, overwriting existing
 * translations with the current package defaults (upsert mode).
 *
 * Unlike install/update — which delegate to the language seeders in their safe
 * insertOrIgnore default (never touching rows a site owner has edited) — this
 * command explicitly opts into upsert so text/position for each (code, location)
 * is forced back to the package value. front/shop seeders run only when their
 * package is present (class_exists guard), keeping the command safe on installs
 * that ship core alone.
 *
 * @aidlc-unit compat-foundation
 */
class LangUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:language-update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh GP247 language rows (core/front/shop), overwriting existing translations with package defaults (upsert)';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        // Ordered core -> front -> shop; front/shop are optional packages, so
        // each is guarded by class_exists and isolated in its own try/catch to
        // keep one package's failure from aborting the others.
        $seeders = [
            'core'  => DataLanguageSeeder::class,
            'front' => 'GP247\\Front\\Admin\\Database\\Seeders\\DataFrontLanguageSeeder',
            'shop'  => 'GP247\\Shop\\Admin\\Database\\Seeders\\DataShopLanguageSeeder',
        ];

        foreach ($seeders as $package => $class) {
            if (!class_exists($class)) {
                $this->line("- [{$package}] skipped (package not installed).");
                continue;
            }

            try {
                $this->laravel->make($class)
                    ->setContainer($this->laravel)
                    ->setCommand($this)
                    ->useUpsert()
                    ->run();
                $this->info("- [{$package}] language updated (upsert).");
            } catch (Throwable $e) {
                gp247_report($e->getMessage());
                $this->error("- [{$package}] failed: ".$e->getMessage());
            }
        }

        $this->info('---------------------');
        $this->info('Language update done!');
    }
}
