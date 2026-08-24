<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrate a safe post-`composer update` refresh for a live site: core-update,
 * then shop-update when the shop is installed, optional language overwrite, an
 * optional opt-in asset/view re-publish, and a cache rebuild. Never runs a
 * destructive (re)install step.
 *
 * Re-publish is opt-in via --publish=<tokens>. composer update refreshes vendor
 * code but not the published copies under public/GP247, app/GP247 and
 * resources/views/vendor/*. Each token names a publish tag (naming its package),
 * and tokens are tiered by impact: gp247:core-public (compiled admin assets) is
 * safe to force; every view/template token overwrites the site's customization
 * surface. There is no --force flag — typing a destructive token IS the consent
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
        'front-view',
        'shop-view-admin',
        'shop-view-front',
    ];

    /**
     * Tokens that overwrite a customization surface (views/templates or the
     * in-place-built storefront CSS). These require consent; only core-public
     * is safe to publish unconditionally.
     *
     * @var array<int, string>
     */
    private const DESTRUCTIVE_TOKENS = [
        'core-view',
        'front-public',
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
        'front-public'    => 'public/GP247/Templates/GP247Front (in-place-built storefront CSS)',
        'front-view'      => 'app/GP247/Templates/GP247Front (live storefront templates)',
        'shop-view-admin' => 'resources/views/vendor/gp247-shop-admin',
        'shop-view-front' => 'app/GP247/Templates/GP247Front (live storefront templates)',
    ];

    /** @var string */
    protected $signature = 'gp247:update
        {--overwrite-lang : Also run gp247:language-update (overwrites edited translations)}
        {--publish= : Re-publish assets/views by tag token, comma-separated: core-public,core-view,front-public,front-view,shop-view-admin,shop-view-front,all. Default: none. Only core-public is safe; view/template tokens overwrite your customizations (see command-line-reference for the impact of each).}';

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

        // WHY: publish before the cache rebuild so freshly published views are
        // picked up by the view cache. Opt-in only — empty when no --publish.
        $published = $this->runPublish($tokens);

        $this->info('==> gp247:cache-rebuild');
        $this->runArtisan('gp247:cache-rebuild');
        $done[] = 'gp247:cache-rebuild';

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
            return self::VALID_TOKENS;
        }

        return $parts;
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
