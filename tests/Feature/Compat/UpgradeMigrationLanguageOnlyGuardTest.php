<?php

namespace GP247\Core\Tests\Feature\Compat;

use GP247\Core\Tests\TestCase;

/**
 * New language labels reach installed sites through the package's language seeder, which every
 * `gp247:*-update` runs with insertOrIgnore. An upgrade migration that only inserts language rows
 * duplicates that path and keeps the upgrade/ folders growing, so from 2026-11-15 such a file is
 * refused. Older files stay: sites that have not run them yet still need them.
 *
 * Static check of the source files; no database.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-language-rows-via-seeder
 * @aidlc-adr compat-foundation_public-release-migration-policy
 */
class UpgradeMigrationLanguageOnlyGuardTest extends TestCase
{
    /** Files dated before this prefix are released and kept as they are. */
    private const FROM = '2026_11_15';

    /** Package => its upgrade/ folder and the seeder that owns its language rows. */
    private const FOLDERS = [
        'core' => ['vendor/gp247/core/src/Database/Migrations/upgrade', 'DataLanguageSeeder'],
        'front' => ['vendor/gp247/front/src/Admin/Database/Migrations/upgrade', 'DataFrontLanguageSeeder'],
        'shop' => ['vendor/gp247/shop/src/Admin/Database/Migrations/upgrade', 'DataShopLanguageSeeder'],
        'mcp' => ['vendor/gp247/mcp/src/Database/Migrations/upgrade', 'its Lang files'],
    ];

    /**
     * Whether a migration body only inserts rows into the languages table.
     *
     * WHY these markers: anything that changes schema, rewrites or removes existing rows, or
     * touches another table is a legitimate upgrade migration; only a pure insert into
     * `languages` is what the seeder already delivers.
     *
     * @param string $code PHP source of the migration.
     * @return bool
     */
    private function onlyInsertsLanguages(string $code): bool
    {
        // Comments do not count: a docblock may mention "update" or other tables.
        $tokens = token_get_all($code);
        $body = '';
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $body .= is_array($token) ? $token[1] : $token;
        }

        $writesLanguages = (bool) preg_match("/(['\"]languages['\"]|Languages::)/", $body)
            && (bool) preg_match('/->\s*insert(OrIgnore)?\s*\(|::\s*insert(OrIgnore)?\s*\(/', $body);
        if (!$writesLanguages) {
            return false;
        }
        // WHY not every Schema:: call: label migrations guard with hasTable(), which changes nothing.
        if (preg_match('/->\s*(create|drop\w*|rename\w*|update|upsert|updateOrInsert|delete|truncate)\s*\(|DB::\s*(statement|unprepared|update|delete)\s*\(|::\s*(create|drop\w*|rename\w*|update|upsert|updateOrInsert|delete|where)\s*\(/', $body)) {
            return false;
        }
        // Any table name other than languages means the file does more than add labels.
        preg_match_all("/(?:->|::)\s*table\s*\(\s*([^)]*)\)/", $body, $tables);
        foreach ($tables[1] as $argument) {
            if (!preg_match("/['\"]languages['\"]/", $argument)) {
                return false;
            }
        }
        return true;
    }

    public function test_the_guard_recognises_a_migration_that_only_adds_labels(): void
    {
        $labelsOnly = <<<'PHP'
<?php
// update the labels of the new screen
return new class extends Migration {
    public function up() {
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore([['code' => 'a', 'text' => 'A', 'position' => 'p', 'location' => 'en']]);
    }
};
PHP;
        $schema = str_replace('public function up() {', "public function up() {\n        Schema::connection(GP247_DB_CONNECTION)->table('x', function () {});", $labelsOnly);
        $overwrite = str_replace('->insertOrIgnore(', '->where("code", "a")->update(["text" => "B"]); $x->insertOrIgnore(', $labelsOnly);
        $otherTable = str_replace(
            'public function up() {',
            "public function up() {\n        DB::table(GP247_DB_PREFIX . 'admin_config')->insertOrIgnore([]);",
            $labelsOnly
        );
        $model = "<?php\nreturn new class extends Migration { public function up() { Languages::insertOrIgnore([]); } };";

        $this->assertTrue($this->onlyInsertsLanguages($labelsOnly), 'labels only');
        $this->assertTrue($this->onlyInsertsLanguages($model), 'labels only, through the model');
        $this->assertFalse($this->onlyInsertsLanguages($schema), 'schema change is allowed');
        $this->assertFalse($this->onlyInsertsLanguages($overwrite), 'overwriting existing text is allowed');
        $this->assertFalse($this->onlyInsertsLanguages($otherTable), 'touching another table is allowed');
    }

    public function test_the_guard_flags_the_released_label_only_migrations(): void
    {
        // WHY: proves the check matches the real shape of label migrations, not only the fixtures.
        $released = base_path('vendor/gp247/core/src/Database/Migrations/upgrade/2026_11_14_075507_seed_online_catalog_labels.php');
        $this->assertFileExists($released);
        $this->assertTrue($this->onlyInsertsLanguages((string) file_get_contents($released)));
    }

    public function test_no_upgrade_migration_from_2026_11_15_only_adds_language_labels(): void
    {
        $offenders = [];
        foreach (self::FOLDERS as $package => [$folder, $seeder]) {
            foreach (glob(base_path($folder) . '/*_*.php') ?: [] as $file) {
                $name = basename($file);
                if (strcmp(substr($name, 0, strlen(self::FROM)), self::FROM) < 0) {
                    continue;
                }
                if ($this->onlyInsertsLanguages((string) file_get_contents($file))) {
                    $offenders[] = "{$package}: {$name} — put the rows in {$seeder} instead";
                }
            }
        }

        $this->assertSame([], $offenders, "Upgrade migrations that only add language labels:\n" . implode("\n", $offenders));
    }
}
