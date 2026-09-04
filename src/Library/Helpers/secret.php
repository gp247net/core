<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * At-rest secret handling for GP247 (ADR compat-foundation_config-secret-at-rest).
 *
 * A secret value is stored with a version-tagged envelope so it can be told apart
 * from legacy plaintext at read time — the DECISION TO DECRYPT is made on the
 * envelope, the DECISION TO ENCRYPT on a flag/cast at write time.
 *
 * Two envelope versions:
 *   - enc:v1:<payload>            — legacy: encrypted with Laravel APP_KEY (Crypt).
 *   - enc:v2:<kid>:<payload>      — current: encrypted with the DEDICATED GP247
 *                                   encryption key (GP247_ENCRYPTION_KEY), falling
 *                                   back to APP_KEY only when the dedicated key is
 *                                   unset. <kid> is a short fingerprint of the key,
 *                                   so key rotation / diagnostics know which key a
 *                                   row was written with.
 *
 * A dedicated key insulates stored secrets from an APP_KEY change (session/CSRF keys
 * are routinely regenerated; long-lived secrets must not die with them).
 */

if (!defined('GP247_SECRET_PREFIX_V1')) {
    /** Legacy envelope tag (APP_KEY / Laravel Crypt). */
    define('GP247_SECRET_PREFIX_V1', 'enc:v1:');
}
if (!defined('GP247_SECRET_PREFIX_V2')) {
    /** Current envelope tag: enc:v2:<kid>: — dedicated GP247 key. */
    define('GP247_SECRET_PREFIX_V2', 'enc:v2:');
}

if (!function_exists('gp247_secret_parse_key') && !in_array('gp247_secret_parse_key', config('gp247_functions_except', []))) {
    /**
     * Decode a config key string ("base64:…" or raw) into raw key bytes.
     *
     * @param string $key Key string from .env / config.
     * @return string Raw key bytes ('' when the input is empty).
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    function gp247_secret_parse_key(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}

if (!function_exists('gp247_secret_key_id') && !in_array('gp247_secret_key_id', config('gp247_functions_except', []))) {
    /**
     * Short, non-secret fingerprint (kid) of a raw key — the first 8 hex chars of its
     * SHA-256. Used only to identify which key a ciphertext belongs to; reveals nothing.
     *
     * @param string $rawKey Raw key bytes.
     * @return string 8-char hex kid ('' for an empty key).
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    function gp247_secret_key_id(string $rawKey): string
    {
        return $rawKey === '' ? '' : substr(hash('sha256', $rawKey), 0, 8);
    }
}

if (!function_exists('gp247_secret_keys') && !in_array('gp247_secret_keys', config('gp247_functions_except', []))) {
    /**
     * The ordered raw key set used for the v2 (dedicated) envelope: the active key
     * first, then previous keys (for reading during rotation). Prefers the dedicated
     * GP247 key; falls back to APP_KEY when it is unset so encryption still works out
     * of the box (with a doctor warning to set the dedicated key).
     *
     * @return array{active: string, previous: array<int, string>, dedicated: bool}
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    function gp247_secret_keys(): array
    {
        $dedicated = (string) config('gp247-config.security.encryption_key', '');

        if ($dedicated !== '') {
            $previous = (array) config('gp247-config.security.encryption_previous_keys', []);
            $isDedicated = true;
        } else {
            $dedicated = (string) config('app.key', '');
            $previous = (array) config('app.previous_keys', []);
            $isDedicated = false;
        }

        return [
            'active' => gp247_secret_parse_key($dedicated),
            'previous' => array_values(array_filter(array_map('gp247_secret_parse_key', (array) $previous))),
            'dedicated' => $isDedicated,
        ];
    }
}

if (!function_exists('gp247_secret_encrypter') && !in_array('gp247_secret_encrypter', config('gp247_functions_except', []))) {
    /**
     * An Encrypter over the v2 key set (active + previous), for the dedicated key.
     * On decrypt it tries the active key then each previous key automatically.
     *
     * @return \Illuminate\Encryption\Encrypter|null Null when no usable key exists.
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    function gp247_secret_encrypter(): ?Encrypter
    {
        $keys = gp247_secret_keys();
        if ($keys['active'] === '') {
            return null;
        }
        $cipher = (string) config('app.cipher', 'AES-256-CBC');
        $encrypter = new Encrypter($keys['active'], $cipher);
        if ($keys['previous'] !== []) {
            $encrypter->previousKeys($keys['previous']);
        }

        return $encrypter;
    }
}

if (!function_exists('gp247_secret_is_encrypted') && !in_array('gp247_secret_is_encrypted', config('gp247_functions_except', []))) {
    /**
     * Whether a raw value carries a well-formed at-rest envelope. Validates STRUCTURE,
     * not just the prefix, so a plaintext value that merely starts with "enc:v1:" is
     * not mistaken for ciphertext (and thus never left unencrypted / read as empty).
     *
     * @param mixed $raw Raw stored value.
     * @return bool
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    function gp247_secret_is_encrypted($raw): bool
    {
        if (!is_string($raw)) {
            return false;
        }
        if (str_starts_with($raw, GP247_SECRET_PREFIX_V2)) {
            return (bool) preg_match('#^enc:v2:[0-9a-f]{8}:[A-Za-z0-9+/=]+$#', $raw);
        }
        if (str_starts_with($raw, GP247_SECRET_PREFIX_V1)) {
            $decoded = json_decode((string) base64_decode(substr($raw, strlen(GP247_SECRET_PREFIX_V1)), true), true);

            return is_array($decoded) && isset($decoded['iv'], $decoded['value'], $decoded['mac']);
        }

        return false;
    }
}

if (!function_exists('gp247_secret_encrypt') && !in_array('gp247_secret_encrypt', config('gp247_functions_except', []))) {
    /**
     * Encrypt a plaintext secret into the current (v2, dedicated-key) envelope.
     * Idempotent: an already-enveloped value is returned unchanged (no double-encrypt);
     * an empty string is left as-is.
     *
     * @param string|null $plaintext Plaintext to protect.
     * @return string Enveloped ciphertext, or '' for empty input.
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    function gp247_secret_encrypt($plaintext): string
    {
        $plaintext = (string) $plaintext;
        if ($plaintext === '' || gp247_secret_is_encrypted($plaintext)) {
            return $plaintext;
        }

        $encrypter = gp247_secret_encrypter();
        if ($encrypter === null) {
            // No key configured — fall back to the app Crypt (v1) so nothing is lost.
            return GP247_SECRET_PREFIX_V1 . Crypt::encryptString($plaintext);
        }

        $keys = gp247_secret_keys();

        return GP247_SECRET_PREFIX_V2 . gp247_secret_key_id($keys['active']) . ':' . $encrypter->encryptString($plaintext);
    }
}

if (!function_exists('gp247_secret_decrypt') && !in_array('gp247_secret_decrypt', config('gp247_functions_except', []))) {
    /**
     * Decrypt an at-rest secret of either envelope version. A value without a valid
     * envelope is returned as-is (legacy plaintext / non-secret). A decryption failure
     * — e.g. the key changed without keeping the old one — is FAIL-SAFE: returns '' and
     * reports, never throws, so the site keeps running (NFR-AVAIL-001).
     *
     * @param mixed $raw Raw stored value.
     * @return string Plaintext, or '' when it cannot be decrypted.
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    function gp247_secret_decrypt($raw): string
    {
        if (!gp247_secret_is_encrypted($raw)) {
            return (string) $raw;
        }

        try {
            if (str_starts_with($raw, GP247_SECRET_PREFIX_V2)) {
                // enc:v2:<kid>:<payload> — strip the "enc:v2:<kid>:" prefix.
                $rest = substr($raw, strlen(GP247_SECRET_PREFIX_V2));
                $payload = substr($rest, strpos($rest, ':') + 1);
                $encrypter = gp247_secret_encrypter();
                if ($encrypter === null) {
                    throw new \RuntimeException('No GP247 encryption key configured');
                }

                return $encrypter->decryptString($payload);
            }

            // Legacy v1 — Laravel Crypt (APP_KEY + app previous keys).
            return Crypt::decryptString(substr($raw, strlen(GP247_SECRET_PREFIX_V1)));
        } catch (\Throwable $e) {
            gp247_report('[gp247 secret] Failed to decrypt a stored secret — the encryption key may have changed. Set GP247_ENCRYPTION_PREVIOUS_KEYS (or APP_PREVIOUS_KEYS for legacy rows), or re-enter the value. ' . $e->getMessage());

            return '';
        }
    }
}

if (!function_exists('gp247_secret_needs_rotation') && !in_array('gp247_secret_needs_rotation', config('gp247_functions_except', []))) {
    /**
     * Whether a stored secret is NOT written with the current active v2 key — i.e. a
     * legacy v1 row, or a v2 row under a previous key. Used by gp247:encryption-key-rotate
     * to find rows to re-encrypt, and by diagnostics.
     *
     * @param mixed $raw Raw stored value.
     * @return bool True when the row should be re-encrypted with the active key.
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-config-secret-at-rest
     * @aidlc-adr compat-foundation_config-secret-at-rest
     */
    function gp247_secret_needs_rotation($raw): bool
    {
        if (!gp247_secret_is_encrypted($raw)) {
            return false;
        }
        if (str_starts_with($raw, GP247_SECRET_PREFIX_V1)) {
            return true;
        }
        $rest = substr($raw, strlen(GP247_SECRET_PREFIX_V2));
        $kid = substr($rest, 0, (int) strpos($rest, ':'));

        return $kid !== gp247_secret_key_id(gp247_secret_keys()['active']);
    }
}
