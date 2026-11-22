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

    private function useKey(string $active, array $previous = []): void
    {
        config([
            'gp247-config.security.encryption_key' => $active,
            'gp247-config.security.encryption_previous_keys' => array_map(
                static fn (string $k): string => str_starts_with($k, 'base64:') ? (string) base64_decode(substr($k, 7)) : $k,
                $previous
            ),
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
        $this->assertSame(0, $exit);
        $rawB = $this->raw('tok');
        $this->assertNotSame($rawA, $rawB);
        $this->assertStringStartsWith('enc:v2:', (string) $rawB);
        $this->assertFalse(gp247_secret_needs_rotation($rawB));
        $this->assertSame('PLAIN', gp247_secret_decrypt($rawB));

        // Idempotent: nothing left to convert.
        Artisan::call('gp247:encryption-key-rotate', ['--json' => true]);
        $this->assertSame($rawB, $this->raw('tok'));
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
