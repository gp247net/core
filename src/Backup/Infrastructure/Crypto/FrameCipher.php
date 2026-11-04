<?php

namespace GP247\Core\Backup\Infrastructure\Crypto;

/**
 * Seals / opens one frame of an archive file with AES-256-GCM.
 *
 * Frame key = HKDF-SHA256(DEK, salt = archive id, info = "gp247-backup-frame").
 * Nonce = 12 random bytes per seal, stored in front of the ciphertext: a frame position written
 * again after an interrupted request never reuses a nonce (security review BK-1 #2).
 * AAD = "GP247BK1" ‖ archive id ‖ uint32 part ‖ uint64 frame ‖ final flag, so a frame cannot be
 * moved, reordered, replayed from another archive, or turned into / out of the closing frame.
 * Random 96-bit nonces stay far below the GCM bound (2^32 frames per key; an archive has a few
 * thousand).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-encryption, US-backup-engine-integrity-manifest
 * @aidlc-adr backup-engine_tamper-evidence, backup-engine_archive-format
 */
final class FrameCipher
{
    public const MAX_PLAINTEXT = 1_048_576;
    public const MAX_PART = 0xFFFFFFFF;
    /** Bytes a seal adds to the plaintext: nonce + tag. */
    public const OVERHEAD = OpenSsl::NONCE_BYTES + OpenSsl::TAG_BYTES;

    private string $key;

    /**
     * @param string $dek       32-byte archive data key.
     * @param string $archiveId Archive id.
     */
    public function __construct(#[\SensitiveParameter] string $dek, private readonly string $archiveId)
    {
        $this->key = hash_hkdf('sha256', $dek, OpenSsl::KEY_BYTES, 'gp247-backup-frame', $archiveId);
    }

    /**
     * @param int    $part      Part index (0 = manifest).
     * @param int    $frame     Frame index inside the part.
     * @param bool   $final     True for the closing frame of the part.
     * @param string $plaintext At most 1 MiB.
     * @return string Nonce ‖ ciphertext ‖ tag.
     */
    public function seal(int $part, int $frame, bool $final, #[\SensitiveParameter] string $plaintext): string
    {
        if (strlen($plaintext) > self::MAX_PLAINTEXT) {
            throw new \InvalidArgumentException('Frame plaintext is larger than 1 MiB.');
        }
        $nonce = random_bytes(OpenSsl::NONCE_BYTES);

        return $nonce . OpenSsl::gcmEncrypt($this->key, $nonce, $plaintext, $this->aad($part, $frame, $final));
    }

    /**
     * @param int    $part   Part index.
     * @param int    $frame  Frame index.
     * @param bool   $final  Closing-frame flag as read from the file.
     * @param string $sealed Nonce ‖ ciphertext ‖ tag.
     * @return string|null Plaintext, or null when it does not authenticate.
     */
    public function open(int $part, int $frame, bool $final, string $sealed): ?string
    {
        if ($part < 0 || $part > self::MAX_PART || $frame < 0 || strlen($sealed) < self::OVERHEAD) {
            return null;
        }

        return OpenSsl::gcmDecrypt(
            $this->key,
            substr($sealed, 0, OpenSsl::NONCE_BYTES),
            substr($sealed, OpenSsl::NONCE_BYTES),
            $this->aad($part, $frame, $final)
        );
    }

    /**
     * @param int  $part
     * @param int  $frame
     * @param bool $final
     * @return string
     */
    private function aad(int $part, int $frame, bool $final): string
    {
        if ($part < 0 || $part > self::MAX_PART || $frame < 0) {
            throw new \InvalidArgumentException('Frame position out of range.');
        }

        return 'GP247BK1' . $this->archiveId . pack('N', $part) . pack('J', $frame) . ($final ? "\x01" : "\x00");
    }
}
