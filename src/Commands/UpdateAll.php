<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use GP247\Core\Support\TemplateSourceAudit;

/**
 * Orchestrate a safe post-`composer update` refresh for a live site: core-update,
 * then shop-update when the shop is installed, optional language overwrite, an
 * optional opt-in asset/view re-publish, and a cache rebuild. Never runs a
 * destructive (re)install step.
 *
 * Re-publish is opt-in via --publish=<tokens>. composer update refreshes vendor
 * code but not the published copies under public/GP247, app/GP247 and
 * resources/views/vendor/*. Each token names a publish tag (naming its package),
 * and tokens are tiered by impact: the compiled-asset tokens (gp247:core-public,
 * gp247:front-public) are safe to force because public/ only mirrors a build that
 * lives in the package; every view/template token overwrites the site's own
 * customization surface. There is no --force flag — typing a destructive token IS the consent
 * (unlike gp247:install, which auto-detects packages); an interactive terminal
 * still warns and confirms (defaulting to "no").
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-003
 * @aidlc-adr system-cli_output-contract
 * @aidlc-adr system-cli_update-asset-publish
 */
class UpdateAll extends GP247Command
{
    /**
     * Publish tokens accepted by --publish, each mapping 1:1 to
     * `vendor:publish --tag=gp247:<token>`.
     *
     * @var array<int, string>
     */
    private const VALID_TOKENS = [
        'core-public',
        'core-view',
        'front-public',
        'front-template',
        'front-view',
        'shop-view-admin',
        'shop-view-front',
    ];

    /**
     * Tokens whose destination is the default template GP247Front. A site that
     * uses a different template must never have this directory re-created by an
     * update (RISK-OPS-template-resurrection): `--publish=all` silently expanded
     * to these and resurrected a directory the site had deliberately removed.
     *
     * @var array<int, string>
     */
    private const GP247FRONT_TOKENS = [
        'front-public',
        'front-template',
        'front-view',
        'shop-view-front',
    ];

    /**
     * Tokens that overwrite a customization surface (views/templates). These
     * require consent.
     *
     * front-public left this list in modification 20260913T200309: the storefront
     * CSS used to be built in place under public/, so re-publishing destroyed the
     * only copy. The build now lives in the package and public/ holds a
     * regenerable mirror of it — the same shape as core-public, which has always
     * been safe to force.
     *
     * @var array<int, string>
     */
    private const DESTRUCTIVE_TOKENS = [
        'core-view',
        'front-template',
        'front-view',
        'shop-view-admin',
        'shop-view-front',
    ];

    /**
     * Human-readable publish destination per token, used in impact warnings.
     *
     * @var array<string, string>
     */
    private const DESTINATIONS = [
        'core-public'     => 'public/GP247 (compiled admin assets)',
        'core-view'       => 'resources/views/vendor/gp247-admin',
        'front-public'    => 'public/GP247/Templates/GP247Front (compiled storefront CSS/JS — regenerable mirror of the package build)',
        'front-template'  => 'app/GP247/Templates/GP247Front (the GP247Front extension shell: AppConfig/Provider/Route/config/function/Lang)',
        'front-view'      => 'app/GP247/Templates/GP247Front (the whole GP247Front Blade tree — served from the package unless published)',
        'shop-view-admin' => 'resources/views/vendor/gp247-shop-admin',
        'shop-view-front' => 'app/GP247/Templates/GP247Front (shop screens of the GP247Front Blade tree — served from the package unless published)',
    ];

    /** @var string */
    protected $signature = 'gp247:update
        {--overwrite-lang : Also run gp247:language-update (overwrites edited translations)}
        {--publish= : Re-publish assets/views by tag token, comma-separated: core-public,core-view,front-public,front-template,front-view,shop-view-admin,shop-view-front,all. Default: none. core-public and front-public are safe (compiled assets, regenerable); view/template tokens overwrite your customizations. "all" skips GP247Front targets on a site that uses another template (see command-line-reference).}';

    /** @var string */
    protected $description = 'Update GP247 after composer update (core [+shop], safe for live sites)';

    /**
     * Orchestrate the update. Validates --publish first (so a typo aborts before
     * any work), then runs core-update, optional shop-update, optional
     * language-update, the opt-in re-publish, and cache-rebuild.
     *
     * @return int Exit code (Command::SUCCESS / Command::FAILURE).
     */
    protected function handleGp247(): int
    {
        // WHY: resolve/validate publish targets up front so an unknown token
        // fails fast with nothing done, rather than after the update steps.
        $tokens = $this->resolvePublishTokens();
        if ($tokens === false) {
            return $this->respondFailure(
                'invalid_publish_target',
                'Unknown publish target(s): ' . implode(', ', $this->invalidPublishTokens)
                    . '. Valid: ' . implode(', ', self::VALID_TOKENS) . ', all',
                ['invalid' => $this->invalidPublishTokens, 'valid' => self::VALID_TOKENS]
            );
        }

        $done = [];

        $this->info('==> gp247:core-update');
        if ($this->runArtisan('gp247:core-update') !== Command::SUCCESS) {
            return $this->respondFailure('core_update_failed', 'gp247:core-update failed', ['completed' => $done]);
        }
        $done[] = 'gp247:core-update';

        // WHY: only touch the front (CMS) when it is actually installed
        // (create-tables migration recorded) — running its upgrade otherwise is
        // meaningless. gp247:front-update is the front counterpart of
        // gp247:shop-update (added with the store 1-1 standardization).
        if ($this->frontInstalled()) {
            $this->info('==> gp247:front-update');
            if ($this->runArtisan('gp247:front-update') !== Command::SUCCESS) {
                return $this->respondFailure('front_update_failed', 'gp247:front-update failed', ['completed' => $done]);
            }
            $done[] = 'gp247:front-update';
        }

        // WHY: only touch the shop when it is actually installed (create-tables
        // migration recorded) — running its upgrade otherwise is meaningless.
        if ($this->shopInstalled()) {
            $this->info('==> gp247:shop-update');
            if ($this->runArtisan('gp247:shop-update') !== Command::SUCCESS) {
                return $this->respondFailure('shop_update_failed', 'gp247:shop-update failed', ['completed' => $done]);
            }
            $done[] = 'gp247:shop-update';
        }

        if ($this->option('overwrite-lang')) {
            $this->info('==> gp247:language-update');
            $this->runArtisan('gp247:language-update');
            $done[] = 'gp247:language-update';
        }

        // WHY: publish before the cache rebuild so the following gp247:cache-rebuild
        // clears the compiled Blade of the freshly published views (they recompile
        // lazily on the next request). Opt-in only — empty when no --publish.
        $published = $this->runPublish($tokens);

        $this->info('==> gp247:cache-rebuild');
        $this->runArtisan('gp247:cache-rebuild');
        $done[] = 'gp247:cache-rebuild';

        $this->hintTemplatePrune();

        return $this->respondSuccess(['completed' => $done, 'published' => $published]);
    }

    /**
     * Invalid tokens captured by resolvePublishTokens() for the failure message.
     *
     * @var array<int, string>
     */
    private array $invalidPublishTokens = [];

    /**
     * Parse and validate the --publish option.
     *
     * @return array<int, string>|false Ordered valid tokens (empty when --publish
     *         is absent/blank); false when any token is unknown (see
     *         $invalidPublishTokens for the offending values).
     */
    private function resolvePublishTokens()
    {
        $raw = $this->option('publish');
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parts = array_values(array_unique(array_filter(
            array_map(static fn (string $p): string => strtolower(trim($p)), explode(',', $raw)),
            static fn (string $p): bool => $p !== ''
        )));

        $known = array_merge(self::VALID_TOKENS, ['all']);
        $invalid = array_values(array_diff($parts, $known));
        if (!empty($invalid)) {
            $this->invalidPublishTokens = $invalid;
            return false;
        }

        // WHY: 'all' expands to the canonical token order so publish/report
        // ordering is deterministic regardless of how the user typed it.
        if (in_array('all', $parts, true)) {
            // WHY filtered here and not in runPublish(): 'all' is a wish for
            // "refresh what this site has", not an instruction to create a
            // template the site does not use. A token the operator typed by name
            // is their own decision and still goes through (they get the warning).
            return $this->filterTemplateTokens(self::VALID_TOKENS);
        }

        return $parts;
    }

    /**
     * Drop GP247Front's publish tokens when this site does not use that template.
     *
     * Only applied to the 'all' expansion — see resolvePublishTokens().
     *
     * @param array<int, string> $tokens Canonical token list.
     * @return array<int, string>
     */
    private function filterTemplateTokens(array $tokens): array
    {
        if ($this->gp247FrontInUse()) {
            return $tokens;
        }

        $dropped = array_values(array_intersect($tokens, self::GP247FRONT_TOKENS));
        if (empty($dropped)) {
            return $tokens;
        }

        $this->addWarning(
            'Skipped ' . implode(', ', $dropped) . ': this site does not use the GP247Front template '
            . '(publishing them would re-create app/GP247/Templates/GP247Front). '
            . 'Pass the token by name if you really want it.'
        );

        return array_values(array_diff($tokens, $dropped));
    }

    /**
     * Whether the GP247Front template is present on this site.
     *
     * True when it is the configured default, when any store selects it, or when
     * its directory still exists. WHY three signals: the directory alone is not
     * enough (a site may have deleted it), the config alone is not enough (it is
     * an env var a site can repoint), and the store rows alone are not enough
     * (the database may be unreachable during an update).
     *
     * @return bool
     */
    protected function gp247FrontInUse(): bool
    {
        if (defined('GP247_TEMPLATE_FRONT_DEFAULT') && GP247_TEMPLATE_FRONT_DEFAULT === 'GP247Front') {
            return true;
        }

        if (is_dir(app_path('GP247/Templates/GP247Front'))) {
            return true;
        }

        try {
            return DB::connection(GP247_DB_CONNECTION)
                ->table('admin_store')
                ->where('template', 'GP247Front')
                ->exists();
        } catch (\Throwable $e) {
            // WHY false: with no database to ask, the filesystem already said the
            // template is absent — re-creating it would be the surprising choice.
            return false;
        }
    }

    /**
     * Run the opt-in re-publish. Safe tokens (core-public) run unconditionally;
     * destructive tokens are the user's own consent (they typed them) — a
     * non-interactive caller runs them with a stderr warning, while an
     * interactive terminal warns, reminds about backups and confirms (default
     * "no"); declining drops only the destructive tokens.
     *
     * @param array<int, string> $tokens Validated publish tokens.
     * @return array<int, string> Tokens actually published.
     */
    private function runPublish(array $tokens): array
    {
        if (empty($tokens)) {
            return [];
        }

        $destructive = array_values(array_intersect($tokens, self::DESTRUCTIVE_TOKENS));
        if (!empty($destructive)) {
            if ($this->isJson() || !$this->input->isInteractive()) {
                // WHY: a non-interactive caller cannot answer a prompt; typing a
                // destructive token is itself the consent, so proceed but never
                // swallow the impact — warn to stderr.
                foreach ($destructive as $token) {
                    $this->addWarning('Re-publishing "' . $token . '" overwrites ' . self::DESTINATIONS[$token] . '.');
                }
            } else {
                foreach ($destructive as $token) {
                    $this->warn('WARNING: "' . $token . '" overwrites ' . self::DESTINATIONS[$token] . ' — local customizations WILL BE LOST.');
                }
                $this->warn('Back up the target folder(s) before proceeding.');
                if (!$this->confirm('Re-publish these targets and overwrite customizations?', false)) {
                    $this->info('Skipped re-publishing customizable targets.');
                    // WHY: refusal only drops the destructive tokens; any safe
                    // token (core-public) still publishes, and update/cache
                    // steps around this are unaffected.
                    $tokens = array_values(array_diff($tokens, $destructive));
                }
            }
        }

        $published = [];
        foreach ($tokens as $token) {
            $this->info('==> vendor:publish --tag=gp247:' . $token);
            // WHY: --force here is vendor:publish's own flag (overwrite existing
            // published files); it is NOT a flag of gp247:update.
            $code = $this->runArtisan('vendor:publish', ['--tag' => 'gp247:' . $token, '--force' => true]);
            if ($code === Command::SUCCESS) {
                $published[] = $token;
            } else {
                // WHY: shared-host write failure on one target must not abort the
                // whole update — degrade softly (NFR-AVAIL-cli-shared-host).
                $this->addWarning('Publish failed for gp247:' . $token . ' (check write permissions).');
            }
        }

        return $published;
    }

    /**
     * Point at gp247:template-prune when published template files are identical
     * to the package copy — those files can never receive an update again, which
     * is invisible unless someone says so right after an update.
     *
     * Only a hint: deleting files on a live site is the operator's decision, so
     * this command never does it (RISK-OPS-template-prune-dataloss).
     *
     * @return void
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-template-source-lifecycle
     */
    private function hintTemplatePrune(): void
    {
        try {
            $stats = TemplateSourceAudit::stats();
        } catch (\Throwable $e) {
            return;
        }

        if (($stats['identical'] ?? 0) < 1) {
            return;
        }

        // WHY guarded: --json must carry exactly one envelope on STDOUT.
        if ($this->isJson()) {
            return;
        }

        $this->info('');
        $this->info('Note: '.$stats['identical'].' published template file(s) are identical to the package copy, '
            . 'so updates can never reach them. Hand them back with:');
        $this->info('  php artisan gp247:template-prune <Template> --dry-run');
    }

    /**
     * Whether the front (CMS) module is installed (its create-tables migration ran).
     *
     * @return bool
     */
    protected function frontInstalled(): bool
    {
        try {
            return DB::connection(GP247_DB_CONNECTION)
                ->table('migrations')
                ->where('migration', '00_00_00_create_tables_front')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the shop module is installed (its create-tables migration ran).
     *
     * @return bool
     */
    protected function shopInstalled(): bool
    {
        try {
            return DB::connection(GP247_DB_CONNECTION)
                ->table('migrations')
                ->where('migration', '00_00_00_create_tables_shop')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
