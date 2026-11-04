<?php

namespace GP247\Core\Backup\Infrastructure\Format;

use GP247\Core\Backup\Domain\KeyRing;
use GP247\Core\Backup\Infrastructure\Crypto\Ecies;
use GP247\Core\Backup\Infrastructure\Crypto\FrameCipher;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Writes an archive of format 1 into a directory: part-NNNNNN.bin files of encrypted frames
 * holding gzip row segments, then manifest.bin (part 0), then header.json last — an archive
 * without a header is unfinished.
 *
 * Needs only the site public key (no password). Work can stop at any segment boundary and go
 * on in a later request: state() returns everything needed by resume(). The state holds the
 * archive data key, so the job runner must store it encrypted (gp247_secret_encrypt) and
 * delete it once the archive is finished or abandoned.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-encryption, US-backup-engine-safe-format, US-backup-engine-integrity-manifest
 * @aidlc-adr backup-engine_archive-format, backup-engine_envelope-encryption, backup-engine_tamper-evidence
 */
final class ArchiveWriter
{
    public const DEFAULT_SEGMENT_ROWS = 5000;
    public const DEFAULT_SEGMENT_BYTES = 4_194_304;
    public const DEFAULT_PART_BYTES = 16_777_216;
    private const STATE_VERSION = 1;

    private FrameCipher $cipher;
    private string $buffer = '';
    private int $bufferRows = 0;

    /**
     * @param string               $dir   Archive directory.
     * @param array<string, mixed> $state Writer state.
     */
    private function __construct(private readonly string $dir, private array $state)
    {
        $dek = base64_decode((string) ($state['dek'] ?? ''), true);
        if ($dek === false || strlen($dek) !== Ecies::DEK_BYTES) {
            throw new InvalidArgumentException('Writer state has no usable data key.');
        }
        $this->cipher = new FrameCipher($dek, $state['archive_id']);
    }

    /**
     * Start a new archive.
     *
     * @param string               $dir     Directory to create (must not exist or be empty).
     * @param KeyRing              $ring    Site public key ring.
     * @param array<string, mixed> $options segment_rows, segment_bytes, part_bytes, compression ("gzip" | "none").
     * @return self
     */
    public static function start(string $dir, KeyRing $ring, array $options = []): self
    {
        if (is_dir($dir) && (scandir($dir) ?: []) !== ['.', '..']) {
            throw new RuntimeException('Archive directory is not empty.');
        }
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new RuntimeException('Cannot create the archive directory.');
        }
        $compression = $options['compression'] ?? (function_exists('gzencode') ? 'gzip' : 'none');
        if (!in_array($compression, ['gzip', 'none'], true)) {
            throw new InvalidArgumentException('Unknown compression.');
        }
        $archiveId = (string) Str::ulid();
        $dek = random_bytes(Ecies::DEK_BYTES);

        return new self($dir, [
            'v' => self::STATE_VERSION,
            'archive_id' => $archiveId,
            'key_id' => $ring->keyId,
            'dek' => base64_encode($dek),
            'dek_wrapped' => Ecies::wrapDek($dek, $ring->publicDer, $archiveId, $ring->keyId),
            'private_key' => $ring->wraps(),
            'compression' => $compression,
            'segment_rows' => max(1, (int) ($options['segment_rows'] ?? self::DEFAULT_SEGMENT_ROWS)),
            'segment_bytes' => max(1024, (int) ($options['segment_bytes'] ?? self::DEFAULT_SEGMENT_BYTES)),
            'part_bytes' => max(1024, (int) ($options['part_bytes'] ?? self::DEFAULT_PART_BYTES)),
            'part' => null,
            'frame' => 0,
            'part_size' => 0,
            'parts' => [],
            'tables' => [],
            'table' => null,
        ]);
    }

    /**
     * Continue an archive from a saved state. Bytes written after that state (a request that
     * died mid-write) are cut off.
     *
     * @param string               $dir   Archive directory.
     * @param array<string, mixed> $state From state().
     * @return self
     */
    public static function resume(string $dir, array $state): self
    {
        if (($state['v'] ?? null) !== self::STATE_VERSION || !is_dir($dir)) {
            throw new InvalidArgumentException('Cannot resume this archive.');
        }
        $writer = new self($dir, $state);
        if ($state['part'] !== null) {
            $path = $writer->partPath((int) $state['part']);
            clearstatcache(true, $path);
            $handle = is_file($path) && filesize($path) >= (int) $state['part_size'] ? fopen($path, 'r+b') : false;
            if ($handle === false || !ftruncate($handle, (int) $state['part_size'])) {
                throw new RuntimeException('Archive part is shorter than its saved state.');
            }
            fclose($handle);
        }

        return $writer;
    }

    /**
     * @return string Archive id (ULID).
     */
    public function archiveId(): string
    {
        return $this->state['archive_id'];
    }

    /**
     * @param string       $name    Table name (unprefixed or prefixed, as the caller chooses).
     * @param list<string> $columns Column names in row order.
     * @return void
     */
    public function beginTable(string $name, array $columns): void
    {
        if ($this->state['table'] !== null) {
            throw new RuntimeException('Another table is still open.');
        }
        if (!RowCodec::validName($name) || isset($this->state['tables'][$name])) {
            throw new InvalidArgumentException('Unsafe or repeated table name.');
        }
        RowCodec::encodeColumns($columns);
        $this->state['tables'][$name] = ['columns' => array_values($columns), 'rows' => 0, 'segments' => []];
        $this->state['table'] = $name;
    }

    /**
     * @param array<int|string, mixed> $values Values in column order, or keyed by column.
     * @return void
     */
    public function addRow(#[\SensitiveParameter] array $values): void
    {
        $table = $this->state['table'] ?? null;
        if ($table === null) {
            throw new RuntimeException('No table is open.');
        }
        $columns = $this->state['tables'][$table]['columns'];
        if (!array_is_list($values)) {
            $ordered = [];
            foreach ($columns as $column) {
                if (!array_key_exists($column, $values)) {
                    throw new InvalidArgumentException('Row is missing a column.');
                }
                $ordered[] = $values[$column];
            }
            $values = $ordered;
        }
        if (count($values) !== count($columns)) {
            throw new InvalidArgumentException('Row does not match the columns.');
        }
        $this->buffer .= RowCodec::encodeRow($values);
        $this->bufferRows++;
        if ($this->bufferRows >= $this->state['segment_rows'] || strlen($this->buffer) >= $this->state['segment_bytes']) {
            $this->flushSegment();
        }
    }

    /**
     * @return void
     */
    public function endTable(): void
    {
        $this->flushSegment();
        $this->state['table'] = null;
    }

    /**
     * Close the current segment and return the state to save between requests.
     *
     * @return array<string, mixed> Contains the data key: store it encrypted.
     */
    public function state(): array
    {
        $this->flushSegment();

        return $this->state;
    }

    /**
     * Close the archive: manifest, then the signed header.
     *
     * @param array<string, mixed> $meta       kind, reason, scope, packages, migrations, consistency…
     * @param string|null          $signingKey Site signing key (32 bytes).
     * @return array<string, mixed> The header written.
     */
    public function finish(array $meta, #[\SensitiveParameter] ?string $signingKey): array
    {
        if ($signingKey === null || strlen($signingKey) !== 32) {
            throw new RuntimeException('The site signing key is not available.');
        }
        if ($this->state['table'] !== null) {
            $this->endTable();
        }
        if ($this->state['part'] !== null) {
            $this->closePart();
        }

        $manifest = json_encode([
            'format' => ['major' => Manifest::FORMAT_MAJOR, 'minor' => Manifest::FORMAT_MINOR],
            'archive_id' => $this->state['archive_id'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'compression' => $this->state['compression'],
            'meta' => $meta,
            'tables' => (object) $this->state['tables'],
            'parts' => $this->state['parts'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        // Same limit as the reader: an archive that could never be opened must fail now, not at restore time.
        if (strlen($manifest) > ArchiveReader::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('The archive index is larger than an archive can hold.');
        }

        $manifestPath = $this->dir . '/manifest.bin';
        PartFile::create($manifestPath);
        $chunks = str_split($manifest, FrameCipher::MAX_PLAINTEXT);
        foreach ($chunks as $i => $chunk) {
            PartFile::append($manifestPath, $this->cipher, 0, $i, false, $chunk);
        }
        PartFile::append($manifestPath, $this->cipher, 0, count($chunks), true, '');

        $header = [
            'format' => Manifest::FORMAT_MAJOR,
            'archive_id' => $this->state['archive_id'],
            'key_id' => $this->state['key_id'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'dek' => $this->state['dek_wrapped'],
            'private_key' => $this->state['private_key'],
            'manifest_frames' => count($chunks) + 1,
            'manifest_sha256' => hash_file('sha256', $manifestPath),
        ];
        $header['hmac'] = HeaderSignature::sign($header, $signingKey);

        // Written as canonical JSON: the reader refuses any other byte form (no duplicate keys,
        // no alternative encodings of a signed value — security review BK-1 #6).
        $tmp = $this->dir . '/header.json.tmp';
        if (file_put_contents($tmp, CanonicalJson::encode($header), LOCK_EX) === false
            || !rename($tmp, $this->dir . '/header.json')) {
            throw new RuntimeException('Cannot write the archive header.');
        }
        $this->state['dek'] = '';

        return $header;
    }

    /**
     * @return void
     */
    private function flushSegment(): void
    {
        if ($this->bufferRows === 0) {
            return;
        }
        $table = $this->state['table'];
        $raw = RowCodec::encodeColumns($this->state['tables'][$table]['columns']) . $this->buffer;
        if (strlen($raw) > RowCodec::MAX_RAW_BYTES) {
            // Same limit as the reader (one row larger than this cannot be restored).
            throw new RuntimeException('A row of table ' . $table . ' is larger than an archive can hold.');
        }
        $stored = RowCodec::pack($raw, $this->state['compression']);
        $this->ensurePart(strlen($stored));

        $first = $this->state['frame'];
        foreach (str_split($stored, FrameCipher::MAX_PLAINTEXT) as $chunk) {
            $this->state['part_size'] += PartFile::append($this->partPath($this->state['part']), $this->cipher, $this->state['part'], $this->state['frame'], false, $chunk);
            $this->state['frame']++;
        }
        $this->state['tables'][$table]['segments'][] = [
            'part' => $this->state['part'],
            'first' => $first,
            'last' => $this->state['frame'] - 1,
            'rows' => $this->bufferRows,
            'raw' => strlen($raw),
            'sha256' => hash('sha256', $stored),
        ];
        $this->state['tables'][$table]['rows'] += $this->bufferRows;
        $this->buffer = '';
        $this->bufferRows = 0;
    }

    /**
     * Open a part, or roll over to a new one when the segment would not fit.
     *
     * @param int $incoming Stored size of the next segment.
     * @return void
     */
    private function ensurePart(int $incoming): void
    {
        if ($this->state['part'] !== null && $this->state['frame'] > 0
            && $this->state['part_size'] + $incoming > $this->state['part_bytes']) {
            $this->closePart();
        }
        if ($this->state['part'] === null) {
            $index = count($this->state['parts']) + 1;
            $this->state['part_size'] = PartFile::create($this->partPath($index));
            $this->state['part'] = $index;
            $this->state['frame'] = 0;
        }
    }

    /**
     * @return void
     */
    private function closePart(): void
    {
        $index = $this->state['part'];
        $path = $this->partPath($index);
        $this->state['part_size'] += PartFile::append($path, $this->cipher, $index, $this->state['frame'], true, '');
        $this->state['parts'][] = [
            'index' => $index,
            'frames' => $this->state['frame'] + 1,
            'bytes' => $this->state['part_size'],
            'sha256' => hash_file('sha256', $path),
        ];
        $this->state['part'] = null;
        $this->state['frame'] = 0;
        $this->state['part_size'] = 0;
    }

    /**
     * @param int $index
     * @return string
     */
    private function partPath(int $index): string
    {
        return $this->dir . '/' . sprintf('part-%06d.bin', $index);
    }
}
