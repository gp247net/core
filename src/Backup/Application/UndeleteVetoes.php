<?php

namespace GP247\Core\Backup\Application;

/**
 * The vetoes asked before "add back deleted records" inserts rows. Classes are registered at
 * boot and resolved from the container when a restore runs.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-restore-reapply
 * @aidlc-adr personal-data-registry_restore-reapply
 */
final class UndeleteVetoes
{
    /** @var array<class-string<UndeleteVeto>, true> */
    private static array $classes = [];

    /**
     * @param class-string<UndeleteVeto> $class
     * @return void
     */
    public static function register(string $class): void
    {
        self::$classes[$class] = true;
    }

    /**
     * @param string                     $realTable
     * @param list<array<string, mixed>> $rows
     * @param bool                       $ownSite The archive is this site's own.
     * @return array<int, string> Index => reason, from every veto (the first reason wins).
     */
    public static function refuse(string $realTable, array $rows, bool $ownSite = true): array
    {
        $out = [];
        if ($rows === []) {
            return $out;
        }
        foreach (array_keys(self::$classes) as $class) {
            $veto = app($class);
            if (!$veto instanceof UndeleteVeto) {
                continue;
            }
            foreach ($veto->refuse($realTable, $rows, $ownSite) as $index => $reason) {
                $out[$index] ??= (string) $reason;
            }
        }

        return $out;
    }

    /**
     * @return void Forget the registered vetoes (tests).
     */
    public static function reset(): void
    {
        self::$classes = [];
    }
}
