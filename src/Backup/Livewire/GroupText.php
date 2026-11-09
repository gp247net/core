<?php

namespace GP247\Core\Backup\Livewire;

use GP247\Core\Backup\Domain\DataGroup;

/**
 * Screen texts of the backup groups: the name, the business description (one part per package that
 * adds tables to the group) and the names of the groups an archive holds. Shared by the backup and
 * restore screens so both describe a group the same way.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-dashboard, US-backup-restore-table-restore, US-backup-engine-data-groups
 * @aidlc-adr backup-engine_data-group-registry
 */
final class GroupText
{
    /**
     * @param string    $key   Group key.
     * @param DataGroup $group Group from the registry.
     * @return string Translated name; a key-based name when the label has no text.
     */
    public static function label(string $key, DataGroup $group): string
    {
        $text = gp247_language_quickly($group->label);

        return $text ?? ($group->label !== '' && !str_contains($group->label, '.') ? $group->label : \Illuminate\Support\Str::headline($key));
    }

    /**
     * @param string    $key   Group key.
     * @param DataGroup $group Group from the registry.
     * @return string Description parts joined by "; ", empty when none has a text yet.
     */
    public static function help(string $key, DataGroup $group): string
    {
        $parts = [];
        foreach ($group->help as $code) {
            $text = gp247_language_quickly($code);
            // WHY: a part whose package has not installed its texts yet is left out rather than
            // shown as a raw language key.
            if ($text !== null && $text !== '') {
                $text = gp247_language_replace($text, ['name' => self::label($key, $group)]);
                $parts[] = $parts ? self::continuing($text) : $text;
            }
        }

        return implode('; ', $parts);
    }

    /**
     * Lower the first letter of a part that follows "; " so the parts read as one sentence;
     * an acronym ("API", "URL") or a script without case is left as is.
     *
     * @param string $text Translated part.
     * @return string
     */
    private static function continuing(string $text): string
    {
        $first = mb_substr($text, 0, 1);
        $second = mb_substr($text, 1, 1);

        return $second !== '' && mb_strtolower($second) === $second && mb_strtoupper($second) !== $second
            ? mb_strtolower($first) . mb_substr($text, 1)
            : $text;
    }

    /**
     * @param string $fileGroup File group key (uploads, extensions, env, lock).
     * @return string
     */
    public static function fileHelp(string $fileGroup): string
    {
        return (string) gp247_language_quickly('admin.backup.files_help.' . $fileGroup, '');
    }

    /**
     * Names of the data and file groups an archive holds, in the order of its scope.
     *
     * @param array<string, mixed>     $scope  Decoded `scope` column.
     * @param array<string, DataGroup> $groups Current groups; a key no longer known stays as is.
     * @return string Comma-separated names, empty when the scope lists none.
     */
    public static function scopeNames(array $scope, array $groups): string
    {
        $names = [];
        foreach ((array) ($scope['groups'] ?? []) as $key) {
            $key = (string) $key;
            $names[] = isset($groups[$key]) ? self::label($key, $groups[$key]) : (gp247_language_quickly('admin.backup.group.' . $key) ?? $key);
        }
        foreach ((array) ($scope['files'] ?? []) as $key) {
            $names[] = gp247_language_quickly('admin.backup.files.' . $key) ?? (string) $key;
        }

        return implode(', ', $names);
    }
}
