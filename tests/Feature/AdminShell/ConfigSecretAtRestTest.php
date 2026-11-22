<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use GP247\Core\Tests\TestCase;

/**
 * At-rest encryption of admin_config secrets (US-CMP-config-secret-at-rest,
 * ADR compat-foundation_config-secret-at-rest): encrypt-on-write (flag) /
 * decrypt-on-read (prefix), no double-encrypt, fail-safe on a bad key, and the
 * known-secret migration path (encrypt + flag, idempotent).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-config-secret-at-rest
 * @aidlc-adr compat-foundation_config-secret-at-rest
 */
class ConfigSecretAtRestTest extends TestCase
{
    use DatabaseTransactions;

    private const GROUP = 'SecretTestGroup';

    protected function tearDown(): void
    {
        $this->resetCaches();
        parent::tearDown();
    }

    private function resetCaches(): void
    {
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(AdminConfig::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $empty);
        }
    }

    /**
     * The raw stored column value, bypassing the decrypt accessor.
     *
     * @param string $key
     * @return string|null
     */
    private function rawColumn(string $key): ?string
    {
        $row = AdminConfig::where('group', self::GROUP)->where('key', $key)->first();

        return $row === null ? null : $row->getRawOriginal('value');
    }

    public function test_encrypt_on_write_decrypt_on_read(): void
    {
        AdminConfig::setConfigValue(self::GROUP, 'api_secret', GP247_STORE_ID_GLOBAL, 'S3CR3T', 1);
        $this->resetCaches();

        // Column holds ciphertext with the envelope; read paths return plaintext.
        $this->assertStringStartsWith('enc:v2:', (string) $this->rawColumn('api_secret'));
        $this->assertSame('S3CR3T', AdminConfig::getAllGlobal()['api_secret']);
        $this->assertSame('S3CR3T', gp247_config('api_secret', GP247_STORE_ID_GLOBAL));
        $this->assertSame('S3CR3T', AdminConfig::where('group', self::GROUP)->where('key', 'api_secret')->first()->value);
    }

    public function test_non_secret_stays_plain(): void
    {
        AdminConfig::setConfigValue(self::GROUP, 'plain', GP247_STORE_ID_GLOBAL, 'hello', 0);

        $this->assertSame('hello', $this->rawColumn('plain'));
    }

    public function test_no_double_encrypt(): void
    {
        AdminConfig::setConfigValue(self::GROUP, 'api_secret', GP247_STORE_ID_GLOBAL, 'ABC', 1);
        $first = $this->rawColumn('api_secret');

        // Re-saving the SAME model (already-encrypted attribute) must not wrap it again.
        $row = AdminConfig::where('group', self::GROUP)->where('key', 'api_secret')->first();
        $row->sort = 5;
        $row->save();

        $this->assertSame($first, $this->rawColumn('api_secret'));
        $this->assertSame('ABC', AdminConfig::where('group', self::GROUP)->where('key', 'api_secret')->first()->value);
    }

    public function test_decrypt_failure_is_failsafe(): void
    {
        // A well-formed v2 envelope written under a FOREIGN key: structurally a valid
        // secret, but the current key set cannot decrypt it → fail-safe empty, no throw.
        $foreignRaw = random_bytes(32);
        $enc = new \Illuminate\Encryption\Encrypter($foreignRaw, (string) config('app.cipher', 'AES-256-CBC'));
        $kid = substr(hash('sha256', $foreignRaw), 0, 8);
        $foreign = 'enc:v2:' . $kid . ':' . $enc->encryptString('x');

        $this->assertTrue(gp247_secret_is_encrypted($foreign));
        $this->assertSame('', gp247_secret_decrypt($foreign));
    }

    public function test_prefix_lookalike_plaintext_is_not_treated_as_secret(): void
    {
        // A user value that merely starts with the tag must NOT be mistaken for ciphertext
        // (else it would be left unencrypted and read back as empty).
        $this->assertFalse(gp247_secret_is_encrypted('enc:v1:my-real-password'));
        $this->assertFalse(gp247_secret_is_encrypted('enc:v2:not-a-secret'));

        AdminConfig::setConfigValue(self::GROUP, 'api_secret', GP247_STORE_ID_GLOBAL, 'enc:v1:literal', 1);
        $this->resetCaches();
        $this->assertSame('enc:v1:literal', AdminConfig::getAllGlobal()['api_secret']);
    }

    public function test_v1_legacy_value_still_decrypts(): void
    {
        // Rows written before the dedicated key (v1 = APP_KEY) must keep decrypting.
        $legacy = 'enc:v1:' . Crypt::encryptString('legacy-secret');

        $this->assertTrue(gp247_secret_is_encrypted($legacy));
        $this->assertSame('legacy-secret', gp247_secret_decrypt($legacy));
    }

    public function test_migration_encrypts_known_and_is_idempotent(): void
    {
        // Seed a legacy PLAINTEXT *_client_secret (security=0), like a pre-upgrade site
        // (GLOBAL store, own group — avoids the local DB's non-numeric store_id rows).
        AdminConfig::insert([
            'group' => self::GROUP, 'code' => 'oauth', 'key' => 'myprovider_client_secret',
            'store_id' => GP247_STORE_ID_GLOBAL, 'value' => 'plainpass', 'security' => 0, 'sort' => 3, 'detail' => 'x',
        ]);

        $migration = require base_path('vendor/gp247/core/src/Database/Migrations/upgrade/2026_09_03_230100_encrypt_known_admin_config_secrets.php');
        $migration->up();
        $migration->up(); // idempotent second run

        $row = AdminConfig::where('group', self::GROUP)->where('key', 'myprovider_client_secret')->first();
        $this->assertStringStartsWith('enc:v2:', (string) $row->getRawOriginal('value'));
        $this->assertSame(1, (int) $row->security);
        $this->assertSame('plainpass', $row->value); // decrypts back
    }
}
