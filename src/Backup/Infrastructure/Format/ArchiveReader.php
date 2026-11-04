<?php

namespace GP247\Core\Backup\Infrastructure\Format;

use Generator;
use GP247\Core\Backup\Application\KeyService;
use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Domain\BackupSecret;
use GP247\Core\Backup\Domain\WrongBackupSecret;
use GP247\Core\Backup\Infrastructure\Crypto\Ecies;
use GP247\Core\Backup\Infrastructure\Crypto\FrameCipher;

/**
 * Opens an archive of format 1. Order is fixed and has no bypass: header shape → format →
 * header signature (site signing key) → manifest hash → unlock with password / recovery key →
 * data key → manifest. Rows of a segment are handed out only after its part hash, every frame
 * and the segment hash have been checked. Restore must call verify() before applying anything.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-integrity-manifest, US-backup-engine-encryption, US-backup-engine-safe-format
 * @aidlc-adr backup-engine_tamper-evidence, backup-engine_archive-format, backup-engine_envelope-encryption
 */
final class ArchiveReader
{
    public const MAX_HEADER_BYTES = 65_536;
    public const MAX_MANIFEST_BYTES = 33_554_432;
    private const HEADER_KEYS = ['format', 'archive_id', 'key_id', 'created_at', 'dek', 'private_key', 'manifest_frames', 'manifest_sha256', 'hmac'];

    /** @var array<int, true> Parts whose file hash matched. */
    private array $checkedParts = [];

    /**
     * @param string               $dir
     * @param array<string, mixed> $header
     * @param array<string, mixed> $manifest
     * @param FrameCipher          $cipher
     */
    private function __construct(
        private readonly string $dir,
        private readonly array $header,
        private readonly array $manifest,
        private readonly FrameCipher $cipher,
    ) {
    }

    /**
     * @param string       $dir    Archive directory.
     * @param BackupSecret $secret Backup password or recovery key.
     * @param KeyService   $keys   Site key service (signing key, key ring).
     * @return self
     * @throws ArchiveRejected   When the archive fails a check.
     * @throws WrongBackupSecret When the secret does not open it.
     */
    public static function open(string $dir, BackupSecret $secret, KeyService $keys): self
    {
        $header = self::readHeader($dir);

        $signingKey = $keys->signingKey();
        if ($signingKey === null) {
            throw new ArchiveRejected(ArchiveRejected::SIGNATURE_UNAVAILABLE);
        }
        if (!HeaderSignature::verify($header, $signingKey)) {
            throw new ArchiveRejected(ArchiveRejected::SIGNATURE_MISMATCH);
        }

        $manifestPath = $dir . '/manifest.bin';
        if (!is_file($manifestPath) || !hash_equals($header['manifest_sha256'], (string) hash_file('sha256', $manifestPath))) {
            throw new ArchiveRejected(ArchiveRejected::MANIFEST_HASH_MISMATCH);
        }

        $private = $keys->unlock($secret, $header);
        $dek = Ecies::unwrapDek($header['dek'], $private, $header['archive_id'], $header['key_id']);
        if ($dek === null) {
            throw new ArchiveRejected(ArchiveRejected::KEY_MISMATCH);
        }
        $cipher = new FrameCipher($dek, $header['archive_id']);

        $json = '';
        foreach (PartFile::read($manifestPath, $cipher, 0, $header['manifest_frames']) as $chunk) {
            $json .= $chunk;
            if (strlen($json) > self::MAX_MANIFEST_BYTES) {
                throw new ArchiveRejected(ArchiveRejected::MANIFEST_INVALID, 'size');
            }
        }
        try {
            $manifest = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ArchiveRejected(ArchiveRejected::MANIFEST_INVALID, 'json');
        }
        if (!is_array($manifest)) {
            throw new ArchiveRejected(ArchiveRejected::MANIFEST_INVALID);
        }

        return new self($dir, $header, Manifest::validate($manifest, $header['archive_id']), $cipher);
    }

    /**
     * @return array<string, mixed> Signed header (no secrets in clear).
     */
    public function header(): array
    {
        return $this->header;
    }

    /**
     * @return array<string, mixed> Validated manifest.
     */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /**
     * Read the whole archive back: every part hash, every frame, every segment.
     *
     * @return void
     * @throws ArchiveRejected On the first problem found.
     */
    public function verify(): void
    {
        foreach ($this->manifest['parts'] as $part) {
            $this->checkPart($part['index']);
            foreach (PartFile::read($this->partPath($part['index']), $this->cipher, $part['index'], $part['frames']) as $_) {
                // authenticated frame by frame inside read()
            }
        }
        foreach ($this->manifest['tables'] as $table) {
            foreach ($table['segments'] as $segment) {
                $this->loadSegment($table['columns'], $segment);
            }
        }
    }

    /**
     * @param string $table Table name as written.
     * @return Generator<int, array<string, mixed>> Rows keyed by column.
     * @throws ArchiveRejected
     */
    public function rows(string $table): Generator
    {
        $entry = $this->manifest['tables'][$table] ?? null;
        if ($entry === null) {
            throw new ArchiveRejected(ArchiveRejected::TABLE_UNKNOWN);
        }
        foreach ($entry['segments'] as $segment) {
            yield from $this->loadSegment($entry['columns'], $segment);
        }
    }

    /**
     * @param list<string>         $columns
     * @param array<string, mixed> $segment
     * @return list<array<string, mixed>>
     */
    private function loadSegment(array $columns, array $segment): array
    {
        $part = $segment['part'];
        $this->checkPart($part);
        $frames = $this->partFrames($part);

        $stored = '';
        foreach (PartFile::read($this->partPath($part), $this->cipher, $part, $frames, $segment['first'], $segment['last']) as $chunk) {
            $stored .= $chunk;
        }
        if (!hash_equals($segment['sha256'], hash('sha256', $stored))) {
            throw new ArchiveRejected(ArchiveRejected::SEGMENT_HASH_MISMATCH, 'part ' . $part);
        }
        $raw = RowCodec::unpack($stored, $this->manifest['compression'], $segment['raw']);
        if ($raw === null) {
            throw new ArchiveRejected(ArchiveRejected::SEGMENT_INVALID, 'part ' . $part . ' size');
        }
        $rows = RowCodec::decode($raw, $columns);
        if (count($rows) !== $segment['rows']) {
            throw new ArchiveRejected(ArchiveRejected::SEGMENT_INVALID, 'part ' . $part . ' rows');
        }

        return $rows;
    }

    /**
     * @param int $index
     * @return void
     */
    private function checkPart(int $index): void
    {
        if (isset($this->checkedParts[$index])) {
            return;
        }
        $path = $this->partPath($index);
        if (!is_file($path)) {
            throw new ArchiveRejected(ArchiveRejected::PART_MISSING, 'part ' . $index);
        }
        $expected = $this->manifest['parts'][$index - 1]['sha256'];
        if (!hash_equals($expected, (string) hash_file('sha256', $path))) {
            throw new ArchiveRejected(ArchiveRejected::PART_HASH_MISMATCH, 'part ' . $index);
        }
        $this->checkedParts[$index] = true;
    }

    /**
     * @param int $index
     * @return int
     */
    private function partFrames(int $index): int
    {
        return $this->manifest['parts'][$index - 1]['frames'];
    }

    /**
     * @param int $index
     * @return string
     */
    private function partPath(int $index): string
    {
        return $this->dir . '/' . sprintf('part-%06d.bin', $index);
    }

    /**
     * Read and shape-check header.json (untrusted until its signature is checked).
     *
     * @param string $dir
     * @return array<string, mixed>
     * @throws ArchiveRejected
     */
    private static function readHeader(string $dir): array
    {
        $path = $dir . '/header.json';
        if (!is_file($path) || filesize($path) > self::MAX_HEADER_BYTES) {
            throw new ArchiveRejected(ArchiveRejected::HEADER_INVALID, 'missing or too large');
        }
        $raw = (string) file_get_contents($path);
        try {
            $header = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            // Only the canonical byte form is accepted: no duplicate keys, no alternative encoding
            // of a signed value that another parser could read differently (review BK-1 #6).
            $canonical = is_array($header) ? CanonicalJson::encode($header) : null;
        } catch (\JsonException) {
            throw new ArchiveRejected(ArchiveRejected::HEADER_INVALID, 'json');
        }
        if (!is_array($header) || array_is_list($header) || $canonical !== $raw) {
            throw new ArchiveRejected(ArchiveRejected::HEADER_INVALID, 'not canonical');
        }
        if (array_diff(array_keys($header), self::HEADER_KEYS) !== []) {
            throw new ArchiveRejected(ArchiveRejected::HEADER_INVALID, 'unknown field');
        }
        if (is_int($header['format'] ?? null) && $header['format'] > Manifest::FORMAT_MAJOR) {
            throw new ArchiveRejected(ArchiveRejected::FORMAT_UNSUPPORTED, 'format ' . $header['format']);
        }

        $valid = ($header['format'] ?? null) === Manifest::FORMAT_MAJOR
            && is_string($header['archive_id'] ?? null) && preg_match('/^[0-9A-Z]{26}$/D', $header['archive_id']) === 1
            && is_string($header['key_id'] ?? null) && preg_match('/^[0-9a-f]{32}$/D', $header['key_id']) === 1
            && is_string($header['created_at'] ?? null)
            && is_array($header['dek'] ?? null)
            && is_array($header['private_key'] ?? null)
            && is_array($header['private_key']['password'] ?? null) && is_array($header['private_key']['recovery'] ?? null)
            && is_int($header['manifest_frames'] ?? null) && $header['manifest_frames'] >= 1
            && $header['manifest_frames'] <= intdiv(self::MAX_MANIFEST_BYTES, FrameCipher::MAX_PLAINTEXT) + 1
            && is_string($header['manifest_sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/D', $header['manifest_sha256']) === 1
            && is_string($header['hmac'] ?? null) && preg_match('/^[0-9a-f]{64}$/D', $header['hmac']) === 1;
        if (!$valid) {
            throw new ArchiveRejected(ArchiveRejected::HEADER_INVALID, 'shape');
        }

        return $header;
    }
}
