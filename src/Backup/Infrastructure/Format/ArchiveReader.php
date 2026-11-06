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
 * header signature (site signing key; skipped only for ORIGIN_UNVERIFIED) → manifest hash → unlock with password / recovery key →
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

    /** Header signed with this site's signing key. */
    public const ORIGIN_SITE = 'site';

    /**
     * Header whose signature cannot be checked here (an archive brought from another server, or
     * a site that lost the key the signing key was encrypted with): only data integrity
     * (AES-GCM frames, part / segment hashes) is checked — ADR backup-engine_tamper-evidence
     * decision 3. Callers must warn and ask for a separate confirmation.
     */
    public const ORIGIN_UNVERIFIED = 'unverified';
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
     * @param string       $origin ORIGIN_SITE (signature required) or ORIGIN_UNVERIFIED.
     * @return self
     * @throws ArchiveRejected   When the archive fails a check.
     * @throws WrongBackupSecret When the secret does not open it.
     */
    public static function open(string $dir, #[\SensitiveParameter] BackupSecret $secret, KeyService $keys, string $origin = self::ORIGIN_SITE): self
    {
        $header = self::signedHeader($dir, self::keyFor($origin, $keys), $origin);

        return self::withDataKey($dir, $header, self::unwrap($header, $secret, $keys));
    }

    /**
     * The data key of one archive, after the same header checks as open(). A restore keeps it
     * (encrypted with the site key) in its job between slices instead of the backup password,
     * so later slices open the archive with openWithDataKey() and the private key is never stored.
     *
     * @param string       $dir
     * @param BackupSecret $secret Backup password or recovery key.
     * @param KeyService   $keys
     * @param string       $origin ORIGIN_SITE or ORIGIN_UNVERIFIED.
     * @return string 32-byte data key.
     * @throws ArchiveRejected
     * @throws WrongBackupSecret
     */
    public static function dataKey(string $dir, #[\SensitiveParameter] BackupSecret $secret, KeyService $keys, string $origin = self::ORIGIN_SITE): string
    {
        return self::unwrap(self::signedHeader($dir, self::keyFor($origin, $keys), $origin), $secret, $keys);
    }

    /**
     * The data key of an archive whose header is all that is on disk yet (an upload being
     * unpacked). Nothing is trusted from this: the import checks the signature, the manifest
     * and every part with this key before the archive enters the catalogue.
     *
     * @param string       $dir    Folder holding header.json.
     * @param BackupSecret $secret Password or recovery key of the site that made the archive.
     * @param KeyService   $keys
     * @return array{dek: string, header: array<string, mixed>}
     * @throws ArchiveRejected
     * @throws WrongBackupSecret
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-import-archive
     */
    public static function dataKeyFromHeader(string $dir, #[\SensitiveParameter] BackupSecret $secret, KeyService $keys): array
    {
        $header = self::readHeader($dir);

        return ['dek' => self::unwrap($header, $secret, $keys), 'header' => $header];
    }

    /**
     * Whether this site's signing key signed the archive. Reading the header needs no secret.
     *
     * @param string      $dir
     * @param string|null $signingKey Site signing key (null = unavailable).
     * @return string ORIGIN_SITE or ORIGIN_UNVERIFIED.
     * @throws ArchiveRejected When the header itself is malformed.
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-import-archive
     */
    public static function originOf(string $dir, #[\SensitiveParameter] ?string $signingKey): string
    {
        $header = self::readHeader($dir);

        return $signingKey !== null && HeaderSignature::verify($header, $signingKey) ? self::ORIGIN_SITE : self::ORIGIN_UNVERIFIED;
    }

    /**
     * The header as written, shape-checked but NOT authenticated — for listing archives found in
     * the vault without a catalogue row (id, date, key id). Never a basis for trusting anything.
     *
     * @param string $dir
     * @return array{archive_id: string, created_at: string, key_id: string}
     * @throws ArchiveRejected
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-import-archive
     */
    public static function peek(string $dir): array
    {
        $header = self::readHeader($dir);

        return ['archive_id' => $header['archive_id'], 'created_at' => $header['created_at'], 'key_id' => $header['key_id']];
    }

    /**
     * @param string     $origin
     * @param KeyService $keys
     * @return string|null
     */
    private static function keyFor(string $origin, KeyService $keys): ?string
    {
        // WHY not read at all for an unverified archive: on a damaged site the key ring rows may
        // be unreadable, and the signature is not checked anyway.
        return $origin === self::ORIGIN_UNVERIFIED ? null : $keys->signingKey();
    }

    /**
     * @param array<string, mixed> $header Signature-checked header.
     * @param BackupSecret         $secret
     * @param KeyService           $keys
     * @return string
     * @throws ArchiveRejected
     */
    private static function unwrap(array $header, #[\SensitiveParameter] BackupSecret $secret, KeyService $keys): string
    {
        $private = $keys->unlock($secret, $header);
        $dek = Ecies::unwrapDek($header['dek'], $private, $header['archive_id'], $header['key_id']);
        if ($dek === null) {
            throw new ArchiveRejected(ArchiveRejected::KEY_MISMATCH);
        }

        return $dek;
    }

    /**
     * Open an archive this site has just written, with the data key the writer still holds —
     * the verify step right after creation, which runs without the backup password. Same checks
     * as open() except unlocking the private key; a wrong key fails on the first frame.
     *
     * @param string      $dir        Archive directory.
     * @param string      $dek        Archive data key (32 bytes).
     * @param string|null $signingKey Site signing key.
     * @param string      $origin     ORIGIN_SITE (signature required) or ORIGIN_UNVERIFIED.
     * @return self
     * @throws ArchiveRejected
     */
    public static function openWithDataKey(string $dir, #[\SensitiveParameter] string $dek, #[\SensitiveParameter] ?string $signingKey, string $origin = self::ORIGIN_SITE): self
    {
        if (strlen($dek) !== Ecies::DEK_BYTES) {
            throw new ArchiveRejected(ArchiveRejected::KEY_MISMATCH);
        }

        return self::withDataKey($dir, self::signedHeader($dir, $signingKey, $origin), $dek);
    }

    /**
     * Header shape, format, signature and manifest hash.
     *
     * @param string      $dir
     * @param string|null $signingKey
     * @param string      $origin ORIGIN_UNVERIFIED skips ONLY the signature; every other check stays.
     * @return array<string, mixed>
     * @throws ArchiveRejected
     */
    private static function signedHeader(string $dir, #[\SensitiveParameter] ?string $signingKey, string $origin = self::ORIGIN_SITE): array
    {
        $header = self::readHeader($dir);
        if ($origin !== self::ORIGIN_UNVERIFIED) {
            if ($origin !== self::ORIGIN_SITE) {
                throw new \InvalidArgumentException('Unknown archive origin.');
            }
            if ($signingKey === null) {
                throw new ArchiveRejected(ArchiveRejected::SIGNATURE_UNAVAILABLE);
            }
            if (!HeaderSignature::verify($header, $signingKey)) {
                throw new ArchiveRejected(ArchiveRejected::SIGNATURE_MISMATCH);
            }
        }
        $manifestPath = $dir . '/manifest.bin';
        if (!is_file($manifestPath) || !hash_equals($header['manifest_sha256'], (string) hash_file('sha256', $manifestPath))) {
            throw new ArchiveRejected(ArchiveRejected::MANIFEST_HASH_MISMATCH);
        }

        return $header;
    }

    /**
     * Decrypt and validate the manifest with the archive data key.
     *
     * @param string               $dir
     * @param array<string, mixed> $header Signature-checked header.
     * @param string               $dek
     * @return self
     * @throws ArchiveRejected
     */
    private static function withDataKey(string $dir, array $header, #[\SensitiveParameter] string $dek): self
    {
        $manifestPath = $dir . '/manifest.bin';
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
        foreach ($this->verifySteps() as $step) {
            $this->verifyStep($step);
        }
    }

    /**
     * The whole-archive check split into small steps, so a verification can run over several
     * requests: every part (file hash + every frame), then every segment of tables and file groups.
     *
     * @return list<array> ['part', index] or ['segment', section, name, position].
     */
    public function verifySteps(): array
    {
        $steps = [];
        foreach ($this->manifest['parts'] as $part) {
            $steps[] = ['part', $part['index']];
        }
        foreach (['tables', 'files'] as $section) {
            foreach ($this->manifest[$section] ?? [] as $name => $entry) {
                foreach (array_keys($entry['segments']) as $position) {
                    $steps[] = ['segment', $section, (string) $name, $position];
                }
            }
        }

        return $steps;
    }

    /**
     * @param array $step One entry of verifySteps().
     * @return void
     * @throws ArchiveRejected
     */
    public function verifyStep(array $step): void
    {
        if (($step[0] ?? null) === 'part' && is_int($step[1] ?? null) && isset($this->manifest['parts'][$step[1] - 1])) {
            $part = $this->manifest['parts'][$step[1] - 1];
            $this->checkPart($part['index']);
            foreach (PartFile::read($this->partPath($part['index']), $this->cipher, $part['index'], $part['frames']) as $_) {
                // authenticated frame by frame inside read()
            }

            return;
        }
        $entry = ($step[0] ?? null) === 'segment' && in_array($step[1] ?? null, ['tables', 'files'], true)
            ? ($this->manifest[$step[1]][$step[2] ?? ''] ?? null) : null;
        $segment = is_array($entry) ? ($entry['segments'][$step[3] ?? -1] ?? null) : null;
        if ($segment === null) {
            throw new \InvalidArgumentException('Unknown verification step.');
        }
        $this->loadSegment($entry['columns'], $segment);
    }

    /**
     * Chunks of the files of one group, in archive order (checked like table rows).
     *
     * @param string $group File group key.
     * @return Generator<int, array{path: string, offset: int, size: int, mtime: int, data: string}>
     * @throws ArchiveRejected
     */
    public function fileChunks(string $group): Generator
    {
        $entry = $this->manifest['files'][$group] ?? null;
        if ($entry === null) {
            throw new ArchiveRejected(ArchiveRejected::TABLE_UNKNOWN);
        }
        foreach ($entry['segments'] as $segment) {
            yield from $this->loadSegment($entry['columns'], $segment);
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
     * @param string $table Table name as written.
     * @return int Number of segments of the table (a restore loads one segment at a time).
     * @throws ArchiveRejected
     */
    public function segmentCount(string $table): int
    {
        $entry = $this->manifest['tables'][$table] ?? throw new ArchiveRejected(ArchiveRejected::TABLE_UNKNOWN);

        return count($entry['segments']);
    }

    /**
     * Rows of one segment of a table, checked like rows().
     *
     * @param string $table    Table name as written.
     * @param int    $position Segment position (0-based).
     * @return list<array<string, mixed>>
     * @throws ArchiveRejected
     */
    public function segmentRows(string $table, int $position): array
    {
        $entry = $this->manifest['tables'][$table] ?? throw new ArchiveRejected(ArchiveRejected::TABLE_UNKNOWN);
        $segment = $entry['segments'][$position] ?? throw new ArchiveRejected(ArchiveRejected::TABLE_UNKNOWN, 'segment');

        return $this->loadSegment($entry['columns'], $segment);
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
            && is_string($header['created_at'] ?? null) && preg_match(Manifest::DATE_PATTERN, $header['created_at']) === 1
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
