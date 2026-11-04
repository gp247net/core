<?php

namespace GP247\Core\Backup\Domain;

/**
 * The recovery key: 32 random bytes, shown once to the site owner as base32 (RFC 4648,
 * no padding — 52 characters) in groups of four. Input is accepted loosely (any case,
 * spaces or dashes), decoding is strict (no stray bits).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-key-setup
 * @aidlc-adr backup-engine_envelope-encryption
 */
final class RecoveryKey
{
    public const BYTES = 32;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const LENGTH = 52;

    /**
     * @param string $raw 32 raw bytes.
     */
    private function __construct(#[\SensitiveParameter] private readonly string $raw)
    {
    }

    /**
     * @return self A new random recovery key.
     */
    public static function generate(): self
    {
        return new self(random_bytes(self::BYTES));
    }

    /**
     * @param string $input Text typed by the user.
     * @return self|null Null when it is not a well-formed recovery key.
     */
    public static function parse(#[\SensitiveParameter] string $input): ?self
    {
        $clean = strtoupper((string) preg_replace('/[\s\-]+/', '', $input));
        if (strlen($clean) !== self::LENGTH || strspn($clean, self::ALPHABET) !== self::LENGTH) {
            return null;
        }
        $bits = '';
        foreach (str_split($clean) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        // 52 × 5 = 260 bits: the 4 trailing bits must be zero for a canonical encoding.
        if (substr($bits, self::BYTES * 8) !== '0000') {
            return null;
        }
        $raw = '';
        foreach (str_split(substr($bits, 0, self::BYTES * 8), 8) as $byte) {
            $raw .= chr(bindec($byte));
        }

        return new self($raw);
    }

    /**
     * @return string 32 raw bytes.
     */
    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * @return string Base32 text in groups of four, e.g. ABCD-EFGH-….
     */
    public function display(): string
    {
        $bits = '';
        foreach (str_split($this->raw) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $bits = str_pad($bits, self::LENGTH * 5, '0');
        $text = '';
        foreach (str_split($bits, 5) as $chunk) {
            $text .= self::ALPHABET[bindec($chunk)];
        }

        return implode('-', str_split($text, 4));
    }

    /**
     * Keep the key out of dumps and logs.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['raw' => '[hidden]'];
    }

    /**
     * Refuse serialization (would persist the key in clear).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A recovery key must not be serialized.');
    }
}
