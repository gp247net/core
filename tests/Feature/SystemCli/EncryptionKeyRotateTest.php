<?php

namespace GP247\Core\Tests\Feature\SystemCli;

use GP247\Core\Casts\Secret;
use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use GP247\Core\Tests\TestCase;

/**
 * Dedicated encryption key + gp247:encryption-key-rotate
 * (US-CMP-config-secret-at-rest, ADR compat-foundation_config-secret-at-rest):
 * a secret written under the old key is re-encrypted with the new active key, old
 * rows stay readable via previous keys during the switch, rotation is idempotent and
 * --dry-run writes nothing. Also covers the reusable Secret cast for non-config columns.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-config-secret-at-rest
 * @aidlc-adr compat-foundation_config-secret-at-rest
 */
class EncryptionKeyRotateTest extends TestCase
{
    use DatabaseTransactions;

    private const GROUP = 'RotateTestGroup';

    private function key(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /** @var string[] Keys the site itself reads its secrets with, in .env form ("base64:..."). */
    private array $siteKeys = [];

    /**
     * Remember the site's own keys before a test swaps them.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $keys = gp247_secret_keys();
        $this->siteKeys = array_map(
            static fn (string $raw): string => 'base64:' . base64_encode($raw),
            array_values(array_filter(array_merge([$keys['active']], $keys['previous'])))
        );
    }

    /**
     * Switch the active key; previous keys stay readable.
     *
     * Previous keys are given exactly as .env gives them ("base64:..."): raw key bytes passed
     * through config would be trimmed by key parsing whenever a random key begins or ends with
     * a whitespace byte. The site's own keys stay readable too, so secrets already in the test
     * database do not make a rotation fail (every change is rolled back with the test). The
     * rotation therefore rewrites those rows inside the test transaction: another suite writing
     * the same database at the same time can hit a lock wait or deadlock.
     *
     * @param string   $active   Active key, "base64:...".
     * @param string[] $previous Previous keys, "base64:...".
     * @return void
     */
    private function useKey(string $active, array $previous = []): void
    {
        config([
            'gp247-config.security.encryption_key' => $active,
            'gp247-config.security.encryption_previous_keys' => array_merge($previous, $this->siteKeys),
        ]);
    }

    private function raw(string $key): ?string
    {
        $row = AdminConfig::where('group', self::GROUP)->where('key', $key)->first();

        return $row === null ? null : $row->getRawOriginal('value');
    }

    public function test_rotate_reencrypts_under_new_key_and_is_idempotent(): void
    {
        $keyA = $this->key();
        $keyB = $this->key();

        // Write a secret under key A.
        $this->useKey($keyA);
        AdminConfig::setConfigValue(self::GROUP, 'tok', GP247_STORE_ID_GLOBAL, 'PLAIN', 1);
        $rawA = $this->raw('tok');
        $this->assertStringStartsWith('enc:v2:', (string) $rawA);

        // Switch active key to B, keep A as previous so old rows still read.
        $this->useKey($keyB, [$keyA]);
        $this->assertTrue(gp247_secret_needs_rotation($rawA));
        $this->assertSame('PLAIN', gp247_secret_decrypt($rawA));

        // Dry-run must not change anything.
        Artisan::call('gp247:encryption-key-rotate', ['--dry-run' => true, '--json' => true]);
        $this->assertSame($rawA, $this->raw('tok'));

        // Real rotation: re-encrypt under key B.
        $exit = Artisan::call('gp247:encryption-key-rotate', ['--json' => true]);
        $this->assertSame(0, $exit, Artisan::output());
        $rawB = $this->raw('tok');
        $this->assertNotSame($rawA, $rawB);
        $this->assertStringStartsWith('enc:v2:', (string) $rawB);
        $this->assertFalse(gp247_secret_needs_rotation($rawB));
        $this->assertSame('PLAIN', gp247_secret_decrypt($rawB));

        // Idempotent: nothing left to convert.
        Artisan::call('gp247:encryption-key-rotate', ['--json' => true]);
        $this->assertSame($rawB, $this->raw('tok'));
    }

    public function test_a_previous_key_whose_bytes_start_or_end_with_whitespace_still_reads_old_rows(): void
    {
        // Raw key bytes may begin or end with any byte, whitespace included; the previous key
        // is given exactly as .env gives it ("base64:..."), and must survive key parsing intact.
        $keyA = 'base64:' . base64_encode("\0" . random_bytes(30) . "\n");
        $keyB = $this->key();

        $this->useKey($keyA);
        AdminConfig::setConfigValue(self::GROUP, 'edge', GP247_STORE_ID_GLOBAL, 'PLAIN', 1);
        $rawA = $this->raw('edge');

        $this->useKey($keyB, [$keyA]);
        $this->assertSame('PLAIN', gp247_secret_decrypt($rawA));

        $this->assertSame(0, Artisan::call('gp247:encryption-key-rotate', ['--json' => true]), Artisan::output());
        $this->assertSame('PLAIN', gp247_secret_decrypt((string) $this->raw('edge')));
    }

    public function test_rotate_fails_when_a_row_cannot_be_decrypted(): void
    {
        // A row under a foreign key, with a new active key and NO previous key that reads it.
        $foreignRaw = random_bytes(32);
        $enc = new \Illuminate\Encryption\Encrypter($foreignRaw, (string) config('app.cipher', 'AES-256-CBC'));
        $kid = substr(hash('sha256', $foreignRaw), 0, 8);
        $orphan = 'enc:v2:' . $kid . ':' . $enc->encryptString('x');
        AdminConfig::insert([
            'group' => self::GROUP, 'code' => 't', 'key' => 'orphan',
            'store_id' => GP247_STORE_ID_GLOBAL, 'value' => $orphan, 'security' => 1, 'sort' => 0, 'detail' => 'x',
        ]);

        $this->useKey($this->key()); // active key cannot read the orphan
        $exit = Artisan::call('gp247:encryption-key-rotate', ['--json' => true]);

        // Non-zero exit warns the operator; the orphan row is NOT overwritten.
        $this->assertNotSame(0, $exit);
        $this->assertSame($orphan, $this->raw('orphan'));
    }

    public function test_secret_cast_encrypts_and_decrypts_any_column(): void
    {
        $this->useKey($this->key());
        $cast = new Secret();
        $model = new AdminConfig();

        $stored = $cast->set($model, 'api_token', 'plain-token', [])['api_token'];
        $this->assertStringStartsWith('enc:v2:', $stored);
        $this->assertSame('plain-token', $cast->get($model, 'api_token', $stored, []));
    }

    public function test_doctor_reports_secret_and_key_checks(): void
    {
        Artisan::call('gp247:doctor', ['--json' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('secret_decryptable', $output);
        $this->assertStringContainsString('encryption_key_dedicated', $output);
    }
}
