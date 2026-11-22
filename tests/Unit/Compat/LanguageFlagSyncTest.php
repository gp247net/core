<?php

namespace GP247\Core\Tests\Unit\Compat;

use GP247\Core\Library\LanguageFlagSync;
use PHPUnit\Framework\TestCase;

/**
 * gp247:core-update copies language flag icons the site is missing (e.g. the
 * Japanese flag) without ever overwriting an icon the site already has.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-upgrade-migration-delivery
 */
class LanguageFlagSyncTest extends TestCase
{
    /** @var array<int, string> */
    private array $dirs = [];

    /**
     * @return string Fresh empty temp directory.
     */
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/gp247-flags-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->dirs[] = $dir;

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir.'/{,*/}*', GLOB_BRACE) ?: [] as $file) {
                is_file($file) && unlink($file);
            }
            foreach (array_reverse(glob($dir.'/*', GLOB_ONLYDIR) ?: []) as $sub) {
                rmdir($sub);
            }
            is_dir($dir) && rmdir($dir);
        }
        parent::tearDown();
    }

    public function test_missing_flags_are_copied_and_existing_ones_are_kept(): void
    {
        $source = $this->tempDir();
        $target = $this->tempDir();
        file_put_contents($source.'/flag_uk.png', 'package-uk');
        file_put_contents($source.'/flag_jp.png', 'package-jp');
        file_put_contents($target.'/flag_uk.png', 'site-uk');

        $result = LanguageFlagSync::copyMissing($source, $target);

        $this->assertSame(['flag_jp.png'], $result['copied']);
        $this->assertSame([], $result['failed']);
        $this->assertSame('package-jp', file_get_contents($target.'/flag_jp.png'));
        $this->assertSame('site-uk', file_get_contents($target.'/flag_uk.png'));
    }

    public function test_target_directory_is_created_when_absent(): void
    {
        $source = $this->tempDir();
        file_put_contents($source.'/flag_jp.png', 'package-jp');
        $target = $this->tempDir().'/language';

        $result = LanguageFlagSync::copyMissing($source, $target);

        $this->assertSame(['flag_jp.png'], $result['copied']);
        $this->assertFileExists($target.'/flag_jp.png');
    }

    public function test_missing_source_directory_copies_nothing(): void
    {
        $result = LanguageFlagSync::copyMissing($this->tempDir().'/none', $this->tempDir());

        $this->assertSame(['copied' => [], 'failed' => []], $result);
    }
}
