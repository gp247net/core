<?php

namespace GP247\Core\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast that encrypts a column at rest with the GP247 secret envelope.
 *
 * Use on ANY model column that stores a secret (not just admin_config):
 *
 *     protected $casts = ['api_token' => \GP247\Core\Casts\Secret::class];
 *
 * The value is transparent plaintext in PHP; the database holds "enc:v2:<kid>:…".
 * Reads fail-safe to '' when the key changed (see gp247_secret_decrypt). Unlike
 * Laravel's built-in "encrypted" cast this uses the DEDICATED GP247 key, a versioned
 * envelope and the shared rotation tooling (gp247:encryption-key-rotate), and never
 * throws on a bad key.
 *
 * Requirements for the host column: type TEXT (ciphertext is long); the value is not
 * searchable/filterable while encrypted (add a separate blind-index column if needed);
 * register the table+column in config('gp247-config.security.encrypted_columns') so
 * gp247:doctor and the rotation command cover it.
 *
 * @implements CastsAttributes<string, string>
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-config-secret-at-rest
 * @aidlc-adr compat-foundation_config-secret-at-rest
 */
class Secret implements CastsAttributes
{
    /**
     * Decrypt on read.
     *
     * @param Model                $model
     * @param string               $key
     * @param mixed                $value
     * @param array<string, mixed> $attributes
     * @return string
     */
    public function get(Model $model, string $key, $value, array $attributes): string
    {
        return function_exists('gp247_secret_decrypt') ? gp247_secret_decrypt($value) : (string) $value;
    }

    /**
     * Encrypt on write.
     *
     * @param Model                $model
     * @param string               $key
     * @param mixed                $value
     * @param array<string, mixed> $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, $value, array $attributes): array
    {
        $stored = function_exists('gp247_secret_encrypt') ? gp247_secret_encrypt((string) $value) : (string) $value;

        return [$key => $stored];
    }
}
