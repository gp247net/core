<?php

namespace GP247\Core\Backup\Application;

/**
 * Where the site backup key ring is kept (admin_config on a real site).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-key-setup
 * @aidlc-adr backup-engine_envelope-encryption
 */
interface KeyStore
{
    /**
     * @param string $key Config key.
     * @return string|null Plain value (secrets already decrypted), or null when absent.
     */
    public function get(string $key): ?string;

    /**
     * @param string $key Config key.
     * @return bool The value is stored encrypted at rest (a config secret) — not plain text someone wrote
     *              into the database (mod 20261110T164834: the signing key is trusted only then).
     */
    public function isEncryptedAtRest(string $key): bool;

    /**
     * Write several values atomically, only if the expected current values still hold
     * (compare-and-set — two set-ups or rotations at once never overwrite each other).
     *
     * @param array<string, string>      $values     Key => plain value.
     * @param list<string>               $secretKeys Keys to store as config secrets (encrypted at rest).
     * @param array<string, string|null> $expect     Key => value it must have now (null = absent).
     * @return void
     * @throws KeyStoreConflict When an expected value no longer holds; nothing is written.
     */
    public function putMany(array $values, array $secretKeys = [], array $expect = []): void;
}
