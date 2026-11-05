<?php

namespace GP247\Core\Backup\Application;

/**
 * Keeps values that are not valid UTF-8 (binary primary keys, file names from Linux uploads)
 * intact through the JSON job cursor and the manifest warnings: `{"b": base64}` like RowCodec,
 * and "b64:" labels in warnings. Without this a single such value makes every save fail.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class CursorValue
{
    /**
     * @param mixed $value Scalar, null or list of them.
     * @return mixed JSON-safe form.
     */
    public static function pack(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'pack'], $value);
        }
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            return ['b' => base64_encode($value)];
        }

        return $value;
    }

    /**
     * @param mixed $value From pack().
     * @return mixed Original value.
     */
    public static function unpack(mixed $value): mixed
    {
        if (is_array($value) && array_keys($value) === ['b'] && is_string($value['b'])) {
            $raw = base64_decode($value['b'], true);

            return $raw === false ? null : $raw;
        }
        if (is_array($value)) {
            return array_map([self::class, 'unpack'], $value);
        }

        return $value;
    }

    /**
     * @param string $text Warning that may embed a path.
     * @return string Valid UTF-8.
     */
    public static function label(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : 'b64:' . base64_encode($text);
    }
}
