<?php

namespace GP247\Core\Backup\Infrastructure\Crypto;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Thin, strict wrapper around the ext-openssl calls used by the backup feature: EC P-256
 * key pairs, ECDH, and AES-256-GCM. Nothing else is needed (no sodium, no APP_KEY).
 *
 * Every call passes the bundled openssl.cnf (hosts without a system config cannot create EC
 * keys otherwise) and drains the OpenSSL error queue afterwards, so harmless config warnings
 * never leak into unrelated code that checks openssl_error_string().
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-encryption
 * @aidlc-adr backup-engine_envelope-encryption
 */
final class OpenSsl
{
    public const CURVE = 'prime256v1';
    public const CIPHER = 'aes-256-gcm';
    public const KEY_BYTES = 32;
    public const NONCE_BYTES = 12;
    public const TAG_BYTES = 16;
    public const CONFIG_FILE = __DIR__ . '/openssl.cnf';

    /**
     * Create a new EC P-256 key pair.
     *
     * @return OpenSSLAsymmetricKey Private key (its public half is available through publicDer()).
     * @throws RuntimeException When OpenSSL cannot create the key.
     */
    public static function generateKeyPair(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(self::options());
        self::drainErrors();
        if ($key === false) {
            throw new RuntimeException('OpenSSL could not create an EC P-256 key.');
        }

        return $key;
    }

    /**
     * Export a private key as PKCS#8 DER (plaintext — callers wrap it immediately).
     *
     * @param OpenSSLAsymmetricKey $key Private key.
     * @return string DER bytes.
     * @throws RuntimeException When the export fails.
     */
    public static function exportPrivateDer(OpenSSLAsymmetricKey $key): string
    {
        $pem = '';
        $ok = openssl_pkey_export($key, $pem, null, self::options());
        self::drainErrors();
        if (!$ok || !is_string($pem)) {
            throw new RuntimeException('OpenSSL could not export the private key.');
        }

        return self::pemToDer($pem);
    }

    /**
     * Load a PKCS#8 DER private key; it must be an EC P-256 key.
     *
     * @param string $der DER bytes.
     * @return OpenSSLAsymmetricKey|null Null when the bytes are not a P-256 private key.
     */
    public static function tryImportPrivateDer(#[\SensitiveParameter] string $der): ?OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private(self::derToPem($der, 'PRIVATE KEY'));
        self::drainErrors();

        return ($key !== false && self::isP256($key)) ? $key : null;
    }

    /**
     * Load a PKCS#8 DER private key or fail.
     *
     * @param string $der DER bytes.
     * @return OpenSSLAsymmetricKey
     * @throws RuntimeException When the bytes are not a P-256 private key.
     */
    public static function importPrivateDer(#[\SensitiveParameter] string $der): OpenSSLAsymmetricKey
    {
        $key = self::tryImportPrivateDer($der);
        if ($key === null) {
            throw new RuntimeException('Not an EC P-256 private key.');
        }

        return $key;
    }

    /**
     * SubjectPublicKeyInfo DER of a key (public or private).
     *
     * @param OpenSSLAsymmetricKey $key Key.
     * @return string DER bytes.
     */
    public static function publicDer(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        self::drainErrors();
        if (!is_array($details) || !isset($details['key'])) {
            throw new RuntimeException('OpenSSL could not read the public key.');
        }

        return self::pemToDer($details['key']);
    }

    /**
     * Load a SubjectPublicKeyInfo DER public key; refuses anything but EC P-256
     * (an attacker-chosen curve must never reach ECDH).
     *
     * @param string $der DER bytes (untrusted).
     * @return OpenSSLAsymmetricKey|null Null when not a valid P-256 public key.
     */
    public static function tryImportPublicDer(string $der): ?OpenSSLAsymmetricKey
    {
        if ($der === '' || strlen($der) > 1024) {
            return null;
        }
        $key = openssl_pkey_get_public(self::derToPem($der, 'PUBLIC KEY'));
        self::drainErrors();

        return ($key !== false && self::isP256($key)) ? $key : null;
    }

    /**
     * Load a public key or fail.
     *
     * @param string $der DER bytes.
     * @return OpenSSLAsymmetricKey
     * @throws RuntimeException When the bytes are not a P-256 public key.
     */
    public static function importPublicDer(string $der): OpenSSLAsymmetricKey
    {
        $key = self::tryImportPublicDer($der);
        if ($key === null) {
            throw new RuntimeException('Not an EC P-256 public key.');
        }

        return $key;
    }

    /**
     * ECDH shared secret (x coordinate, 32 bytes).
     *
     * @param OpenSSLAsymmetricKey $peerPublic Peer public key.
     * @param OpenSSLAsymmetricKey $private    Own private key.
     * @return string|null Null when the derivation fails.
     */
    public static function ecdh(OpenSSLAsymmetricKey $peerPublic, OpenSSLAsymmetricKey $private): ?string
    {
        $secret = openssl_pkey_derive($peerPublic, $private, self::KEY_BYTES);
        self::drainErrors();

        return (is_string($secret) && strlen($secret) === self::KEY_BYTES) ? $secret : null;
    }

    /**
     * AES-256-GCM encrypt.
     *
     * @param string $key       32-byte key.
     * @param string $nonce     12-byte nonce (never reused with the same key).
     * @param string $plaintext Plaintext.
     * @param string $aad       Additional authenticated data.
     * @return string Ciphertext followed by the 16-byte tag.
     * @throws RuntimeException On wrong key / nonce size or OpenSSL failure.
     */
    public static function gcmEncrypt(#[\SensitiveParameter] string $key, string $nonce, #[\SensitiveParameter] string $plaintext, string $aad): string
    {
        if (strlen($key) !== self::KEY_BYTES || strlen($nonce) !== self::NONCE_BYTES) {
            throw new RuntimeException('AES-256-GCM needs a 32-byte key and a 12-byte nonce.');
        }
        $tag = '';
        $ct = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_BYTES);
        self::drainErrors();
        if ($ct === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('AES-256-GCM encryption failed.');
        }

        return $ct . $tag;
    }

    /**
     * AES-256-GCM decrypt and authenticate.
     *
     * @param string $key    32-byte key.
     * @param string $nonce  12-byte nonce.
     * @param string $sealed Ciphertext followed by the 16-byte tag.
     * @param string $aad    Additional authenticated data.
     * @return string|null Plaintext, or null when anything does not authenticate.
     */
    public static function gcmDecrypt(#[\SensitiveParameter] string $key, string $nonce, string $sealed, string $aad): ?string
    {
        if (strlen($key) !== self::KEY_BYTES || strlen($nonce) !== self::NONCE_BYTES || strlen($sealed) < self::TAG_BYTES) {
            return null;
        }
        $tag = substr($sealed, -self::TAG_BYTES);
        $ct = substr($sealed, 0, -self::TAG_BYTES);
        $plain = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        self::drainErrors();

        return $plain === false ? null : $plain;
    }

    /**
     * Options for key creation / export, with the bundled config file.
     *
     * @return array<string, mixed>
     */
    private static function options(): array
    {
        return [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => self::CURVE,
            'config' => self::CONFIG_FILE,
        ];
    }

    /**
     * @param OpenSSLAsymmetricKey $key
     * @return bool True for an EC key on P-256.
     */
    private static function isP256(OpenSSLAsymmetricKey $key): bool
    {
        $details = openssl_pkey_get_details($key);
        self::drainErrors();

        return is_array($details)
            && ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC
            && ($details['ec']['curve_name'] ?? null) === self::CURVE;
    }

    /**
     * @param string $pem PEM text.
     * @return string DER bytes.
     */
    private static function pemToDer(#[\SensitiveParameter] string $pem): string
    {
        $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
        $der = base64_decode((string) $body, true);
        if ($der === false || $der === '') {
            throw new RuntimeException('Malformed PEM from OpenSSL.');
        }

        return $der;
    }

    /**
     * @param string $der   DER bytes.
     * @param string $label PEM label.
     * @return string PEM text.
     */
    private static function derToPem(#[\SensitiveParameter] string $der, string $label): string
    {
        return '-----BEGIN ' . $label . "-----\n" . chunk_split(base64_encode($der), 64, "\n") . '-----END ' . $label . "-----\n";
    }

    /**
     * Empty the OpenSSL error queue (config-file warnings on hosts without openssl.cnf).
     *
     * @return void
     */
    private static function drainErrors(): void
    {
        $guard = 0;
        while (openssl_error_string() !== false && $guard++ < 100) {
            // drain
        }
    }
}
