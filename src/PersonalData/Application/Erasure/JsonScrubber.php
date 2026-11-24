<?php

namespace GP247\Core\PersonalData\Application\Erasure;

use GP247\Core\PersonalData\Domain\KnownValues;

/**
 * Empties the declared keys of a JSON document (dot paths, also inside lists: a path is applied
 * to every element of a list it meets). The document stays valid JSON; keys that do not exist
 * are left alone. Text that is not JSON is scrubbed as free text instead.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-free-text-scrub
 * @aidlc-adr personal-data-registry_anonymisation-rules
 */
final class JsonScrubber
{
    /**
     * @param string       $json
     * @param list<string> $paths e.g. ["destination.name", "destination.phone", "note"].
     * @param KnownValues  $known
     * @return string
     */
    public static function scrub(string $json, array $paths, KnownValues $known): string
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return $known->scrub($json);
        }
        if (!is_array($data)) {
            return $known->scrub($json);
        }
        foreach ($paths as $path) {
            $data = self::clear($data, explode('.', $path));
        }

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<string>             $segments
     * @return array<int|string, mixed>
     */
    private static function clear(array $data, array $segments): array
    {
        if (array_is_list($data) && $data !== []) {
            return array_map(fn ($item) => is_array($item) ? self::clear($item, $segments) : $item, $data);
        }
        $head = array_shift($segments);
        if ($head === null || !array_key_exists($head, $data)) {
            return $data;
        }
        if ($segments === []) {
            $data[$head] = null;

            return $data;
        }
        if (is_array($data[$head])) {
            $data[$head] = self::clear($data[$head], $segments);
        }

        return $data;
    }
}
