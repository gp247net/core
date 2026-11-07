<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Domain\RemoteLayout;

/**
 * The "fetch" phase of an import job (decision BK-6 #5): the files of an archive at the external
 * destination are copied one by one into the import's folder of the vault, each checked against
 * the size the destination listed when the fetch started (never more is read). The cursor keeps
 * the next file, so the copy goes on across slices. After it, the import's own phases check the
 * signature and every hash before the archive enters the catalogue.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-fetch-remote, US-backup-storage-chunked-upload
 * @aidlc-adr backup-storage_optional-adapters, backup-engine_sliced-job-runner
 */
final class FetchStep
{
    private const CHUNK = 1_048_576;

    /**
     * @param OffsiteService|null $offsite
     */
    public function __construct(private ?OffsiteService $offsite = null)
    {
    }

    /**
     * @param array<string, mixed> $cursor Import cursor (phase "fetch"), updated in place.
     * @param SliceContext         $slice
     * @return bool True when every file is here (the phase moved on to "open").
     * @throws RestoreAborted   A file does not match, or the destination refuses for good.
     * @throws DestinationFailure A failure a later slice may cure.
     * @throws LeaseLost
     */
    public function run(#[\SensitiveParameter] array &$cursor, SliceContext $slice): bool
    {
        $offsite = $this->offsite ??= new OffsiteService;
        $fetch = (array) $cursor['fetch'];
        $dir = (string) $cursor['dir'];
        $from = (int) $fetch['i'];
        try {
            $destination = $offsite->destination();
            while ($fetch['i'] < count($fetch['order'])) {
                $began = microtime(true);
                $name = (string) $fetch['order'][$fetch['i']];
                $expected = (int) $fetch['files'][$name];
                $path = RemoteLayout::path((string) $fetch['site'], (string) $cursor['archive_id'], $name);
                // Written under its final name: a slice that dies mid-file starts that file again
                // (truncated), and the import's clean-up knows every archive file name.
                $target = $dir . DIRECTORY_SEPARATOR . $name;
                $stream = $destination->read($path);
                $out = fopen($target, 'wb');
                $written = 0;
                try {
                    while (!feof($stream) && $written <= $expected) {
                        $chunk = fread($stream, self::CHUNK);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $written += (int) fwrite($out, $chunk);
                    }
                } finally {
                    fclose($stream);
                    fclose($out);
                }
                if ($written !== $expected) {
                    @unlink($target);
                    throw new RestoreAborted('fetch_mismatch');
                }
                $fetch['i']++;
                $cursor['fetch'] = $fetch;
                // WHY: as UploadStep — a file that would not fit in the time left waits for the next slice.
                $short = microtime(true) + (microtime(true) - $began) > $slice->deadline;
                if ($fetch['i'] < count($fetch['order']) && ($slice->expired() || $short || $slice->saveDue())) {
                    $slice->save($cursor);
                    if ($slice->expired() || $short) {
                        return false;
                    }
                }
            }
        } catch (DestinationFailure $e) {
            if ($e->permanent() || $e->reason === DestinationFailure::NOT_FOUND) {
                throw new RestoreAborted('fetch_' . $e->reason);
            }
            $cursor['fetch'] = $fetch;
            // Attempts restart only when a file got through in this slice (see UploadStep).
            $slice->save($cursor, $fetch['i'] > $from);

            throw $e;
        }
        $cursor['phase'] = 'open';
        // WHY: the one-hour limit of the data key counts from here (ImportStep::keyExpired), so
        // a large archive that took long to come back still has its full hour to be checked.
        $cursor['fetched_at'] = now()->getTimestamp();
        unset($cursor['fetch']);

        return true;
    }
}
