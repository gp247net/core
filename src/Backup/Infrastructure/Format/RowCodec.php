<?php

namespace GP247\Core\Backup\Infrastructure\Format;

use GP247\Core\Backup\Domain\ArchiveRejected;
use InvalidArgumentException;

/**
 * Row segments: NDJSON — line 1 = column names, each next line = one row as a JSON array of
 * values (null, bool, number, UTF-8 string, or {"b": base64} for bytes that are not UTF-8).
 * Values only, never statements; compressed with gzip per segment.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-safe-format
 * @aidlc-adr backup-engine_archive-format
 */
final class RowCodec
{
    public const MAX_RAW_BYTES = 268_435_456;
    private const NAME_PATTERN = '/^[A-Za-z0-9_]{1,64}$/D';
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @param mixed $name
     * @return bool True for a safe table / column name.
     */
    public static function validName(mixed $name): bool
    {
        return is_string($name) && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * @param list<string> $columns
     * @return string Header line.
     * @throws InvalidArgumentException On an unsafe or duplicate name.
     */
    public static function encodeColumns(array $columns): string
    {
        if ($columns === [] || !array_is_list($columns) || count(array_unique($columns)) !== count($columns)) {
            throw new InvalidArgumentException('Columns must be a non-empty list of unique names.');
        }
        foreach ($columns as $column) {
            if (!self::validName($column)) {
                throw new InvalidArgumentException('Unsafe column name.');
            }
        }

        return json_encode($columns, self::JSON_FLAGS) . "\n";
    }

    /**
     * @param list<mixed> $values Row values in column order.
     * @return string Row line.
     * @throws InvalidArgumentException On a value that is not a scalar or null.
     */
    public static function encodeRow(#[\SensitiveParameter] array $values): string
    {
        $encoded = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $encoded[] = mb_check_encoding($value, 'UTF-8') ? $value : ['b' => base64_encode($value)];
            } elseif ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
                $encoded[] = $value;
            } else {
                throw new InvalidArgumentException('Row values must be scalars or null.');
            }
        }

        return json_encode($encoded, self::JSON_FLAGS) . "\n";
    }

    /**
     * @param string $raw         Segment text.
     * @param string $compression "gzip" or "none".
     * @return string Stored bytes.
     */
    public static function pack(#[\SensitiveParameter] string $raw, string $compression): string
    {
        if ($compression === 'none') {
            return $raw;
        }
        $packed = $compression === 'gzip' && function_exists('gzencode') ? gzencode($raw, 6) : false;
        if ($packed === false) {
            throw new InvalidArgumentException('Compression not available: ' . $compression);
        }

        return $packed;
    }

    /**
     * Inflate a stored segment to exactly its signed size (never more — zip bombs stop here).
     *
     * @param string $stored      Stored bytes.
     * @param string $compression "gzip" or "none".
     * @param int    $rawSize     Size recorded in the signed manifest.
     * @return string|null Segment text, or null when it does not match.
     */
    public static function unpack(string $stored, string $compression, int $rawSize): ?string
    {
        if ($rawSize < 1 || $rawSize > self::MAX_RAW_BYTES) {
            return null;
        }
        if ($compression === 'none') {
            return strlen($stored) === $rawSize ? $stored : null;
        }
        if ($compression !== 'gzip' || !function_exists('gzdecode')) {
            return null;
        }
        // WHY @: gzdecode() warns on corrupt input or when the output exceeds the limit; both are
        // reported to the caller as "does not match".
        $raw = @gzdecode($stored, $rawSize);

        return (is_string($raw) && strlen($raw) === $rawSize) ? $raw : null;
    }

    /**
     * @param string       $raw     Segment text.
     * @param list<string> $columns Columns recorded for the table in the manifest.
     * @return list<array<string, mixed>> Rows keyed by column.
     * @throws ArchiveRejected When the segment does not have the expected shape.
     */
    public static function decode(#[\SensitiveParameter] string $raw, array $columns): array
    {
        $lines = explode("\n", $raw);
        if (count($lines) < 2 || array_pop($lines) !== '') {
            throw new ArchiveRejected(ArchiveRejected::SEGMENT_INVALID, 'line structure');
        }
        $header = self::json(array_shift($lines));
        if (!is_array($header) || !array_is_list($header)) {
            throw new ArchiveRejected(ArchiveRejected::SEGMENT_INVALID, 'columns');
        }
        foreach ($header as $name) {
            if (!self::validName($name)) {
                throw new ArchiveRejected(ArchiveRejected::NAME_INVALID, 'column');
            }
        }
        if ($header !== $columns) {
            throw new ArchiveRejected(ArchiveRejected::SEGMENT_INVALID, 'columns differ from manifest');
        }

        $rows = [];
        foreach ($lines as $line) {
            $values = self::json($line);
            if (!is_array($values) || !array_is_list($values) || count($values) !== count($columns)) {
                throw new ArchiveRejected(ArchiveRejected::SEGMENT_INVALID, 'row shape');
            }
            $rows[] = array_combine($columns, array_map([self::class, 'decodeValue'], $values));
        }

        return $rows;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function decodeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (is_array($value) && count($value) === 1 && is_string($value['b'] ?? null)) {
            $bytes = base64_decode($value['b'], true);
            if ($bytes !== false) {
                return $bytes;
            }
        }
        throw new ArchiveRejected(ArchiveRejected::SEGMENT_INVALID, 'value');
    }

    /**
     * @param string $line
     * @return mixed
     */
    private static function json(string $line): mixed
    {
        try {
            return json_decode($line, true, 3, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ArchiveRejected(ArchiveRejected::SEGMENT_INVALID, 'json');
        }
    }
}
