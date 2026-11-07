<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;

/**
 * Keep the N newest successful archives: one count for manual / scheduled archives (7 by
 * default), another for snapshots taken before a risky change (3 by default). Pinned archives
 * are outside both counts and never removed; the newest successful archive is always kept;
 * so is the newest archive still waiting for (or failing) its off-site copy — enough to copy it
 * out once the destination is back (BK-7, ⚑ #7). Older uncopied archives follow the counts, so a
 * destination down for weeks cannot fill the disk.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-retention
 * @aidlc-adr backup-engine_core-module-layout
 */
final class CountRetentionPolicy implements RetentionPolicy
{
    /**
     * {@inheritdoc}
     */
    public function select(array $archives, array $settings): array
    {
        $limits = [
            'regular' => max(1, (int) ($settings['keep'] ?? 7)),
            'pre_change' => max(1, (int) ($settings['keep_pre_change'] ?? 3)),
        ];
        $kept = ['regular' => 0, 'pre_change' => 0];
        $newest = $archives[0]->id ?? null;
        $newestUncopied = self::newestUncopied($archives);
        $remove = [];

        foreach ($archives as $archive) {
            // WHY imported archives are never removed by the keep rule: they carry their original
            // date, so they would be the "oldest" and go first — the person deletes them by hand.
            if ((bool) $archive->pinned || (string) $archive->reason === Archive::REASON_IMPORT) {
                continue;
            }
            $class = Archive::isPreChange((string) $archive->reason) ? 'pre_change' : 'regular';
            $protected = $archive->id === $newest || ($newestUncopied !== null && $archive->id === $newestUncopied);
            if ($protected || $kept[$class] < $limits[$class]) {
                $kept[$class]++;
                continue;
            }
            $remove[] = (string) $archive->id;
        }

        return $remove;
    }

    /**
     * @param list<object> $archives Successful archives, newest first.
     * @return mixed Id of the newest archive whose off-site copy is pending or failed, null when none.
     *
     * @aidlc-unit backup-engine
     * @aidlc-story US-backup-engine-retention
     */
    public static function newestUncopied(array $archives): mixed
    {
        foreach ($archives as $archive) {
            if (in_array($archive->offsite ?? Archive::OFFSITE_NONE, [Archive::OFFSITE_PENDING, Archive::OFFSITE_FAILED], true)) {
                return $archive->id;
            }
        }

        return null;
    }
}
