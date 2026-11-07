<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Backup\Infrastructure\Format\CanonicalJson;
use GP247\Core\Backup\Infrastructure\Format\ResumableUnpacker;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One slice of an "import" job. Phases, each resumable:
 *
 *   unpack  the `.gp247bak` file is split into an archive folder inside the vault's incoming area;
 *   fetch   (instead of unpack, BK-6) the archive's files are copied from the external destination
 *           into that folder (FetchStep);
 *   open    signature: signed with this site's key ⇒ origin "site", otherwise "unverified" (only
 *           data integrity can be checked — ADR tamper-evidence decision 3); the header must be the
 *           one whose data key was unwrapped when the import started;
 *   verify  every part, frame and segment read back with the data key;
 *   adopt   the folder moves into the vault under the archive id and the catalogue row is written
 *           (status ok, reason "import", the archive's own kind and date).
 *
 * Any failure removes the unpacked folder and, for an upload, the uploaded file.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-import-archive
 * @aidlc-adr backup-engine_tamper-evidence, backup-engine_sliced-job-runner
 */
final class ImportStep
{
    /** Kinds a brought-in archive may keep; anything else is catalogued as "imported". */
    private const KINDS = [Archive::KIND_SNAPSHOT, Archive::KIND_SELECTIVE, Archive::KIND_FULL];

    /** Ceiling of the "fetch" phase (copy back from the external destination), seconds. */
    public const FETCH_TTL_SECONDS = 6 * 3600;

    /**
     * @param JobRepository     $jobs
     * @param ArchiveRepository $archives
     * @param KeyService        $keys
     * @param LocalVault        $vault
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ArchiveRepository $archives,
        private readonly KeyService $keys,
        private readonly LocalVault $vault,
        private ?FetchStep $fetch = null,
    ) {
    }

    /**
     * @param object       $job   Job row (lease held).
     * @param SliceContext $slice
     * @return bool True when the job ended.
     * @throws LeaseLost
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function run(object $job, SliceContext $slice): bool
    {
        $cursor = $this->jobs->cursor($job);
        try {
            if (self::keyExpired($cursor)) {
                throw new RestoreAborted('key_expired');
            }
            $reader = null;
            while (true) {
                $completed = match ($cursor['phase'] ?? null) {
                    'unpack' => $this->unpack($cursor, $slice),
                    // BK-6: an archive copied back from the external destination.
                    'fetch' => ($this->fetch ??= new FetchStep)->run($cursor, $slice),
                    'open' => $this->open($cursor, $reader),
                    'verify' => $this->verify($cursor, $reader, $slice),
                    'adopt' => $this->adopt($job, $cursor, $slice),
                    default => throw new \RuntimeException('Unknown import phase.'),
                };
                if ($cursor['phase'] === 'done') {
                    return true;
                }
                $slice->save($cursor);
                if (!$completed || $slice->expired()) {
                    return false;
                }
            }
        } catch (ArchiveRejected $e) {
            $this->failWith($job, $slice, $cursor, 'archive_rejected:' . $e->reason);
        } catch (RestoreRefused $e) {
            $this->failWith($job, $slice, $cursor, $e->reason);
        } catch (RestoreAborted $e) {
            $this->failWith($job, $slice, $cursor, $e->reason);
        }

        return true;
    }

    /**
     * The unwrapped data key of an import is usable for one hour (RestoreStep::KEY_TTL_SECONDS).
     * An archive copied back from the external destination (phase "fetch") gets its own ceiling
     * of FETCH_TTL_SECONDS for the transfer, and the hour starts once the copy is complete
     * (BK-7, ⚑ #6) — a large archive on a slow link is not lost to the clock.
     *
     * @param array<string, mixed> $cursor Import cursor.
     * @return bool
     *
     * @aidlc-story US-backup-restore-import-archive, US-backup-storage-fetch-remote
     */
    public static function keyExpired(#[\SensitiveParameter] array $cursor): bool
    {
        $now = now()->getTimestamp();
        if (($cursor['phase'] ?? null) === 'fetch') {
            return $now - (int) ($cursor['started'] ?? 0) > self::FETCH_TTL_SECONDS;
        }

        return $now - (int) ($cursor['fetched_at'] ?? $cursor['started'] ?? 0) > RestoreStep::KEY_TTL_SECONDS;
    }

    /**
     * Remove what an import that did not finish left behind (the job is already ended).
     *
     * @param array<string, mixed> $cursor
     * @return void
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function abort(#[\SensitiveParameter] array $cursor): void
    {
        if (is_string($cursor['dir'] ?? null) && str_starts_with(str_replace('\\', '/', $cursor['dir']), str_replace('\\', '/', $this->vault->incomingDir()))) {
            ResumableUnpacker::clean($cursor['dir']);
        }
        $this->dropSource($cursor);
    }

    /**
     * @param array<string, mixed> $cursor
     * @param SliceContext         $slice
     * @return bool
     */
    private function unpack(#[\SensitiveParameter] array &$cursor, SliceContext $slice): bool
    {
        $cursor['unpack'] = ResumableUnpacker::step((string) $cursor['source'], (string) $cursor['dir'], (array) $cursor['unpack'], function (array $state) use (&$cursor, $slice) {
            if ($slice->saveDue()) {
                $cursor['unpack'] = $state;
                $slice->save($cursor);
            }

            return !$slice->expired();
        });
        if (empty($cursor['unpack']['done'])) {
            return false;
        }
        $cursor['phase'] = 'open';

        return true;
    }

    /**
     * @param array<string, mixed> $cursor
     * @param ArchiveReader|null   $reader
     * @return bool
     */
    private function open(#[\SensitiveParameter] array &$cursor, ?ArchiveReader &$reader): bool
    {
        $reader = $this->reader($cursor);
        $meta = (array) ($reader->manifest()['meta'] ?? []);
        $cursor['meta'] = [
            'kind' => in_array($meta['kind'] ?? null, self::KINDS, true) ? $meta['kind'] : Archive::KIND_IMPORTED,
            'consistency' => in_array($meta['consistency'] ?? null, [Archive::CONSISTENT, Archive::MULTI_SLICE], true) ? $meta['consistency'] : null,
            'scope' => self::scope($meta['scope'] ?? null),
            'created_at' => (string) ($reader->manifest()['created_at'] ?? $reader->header()['created_at']),
            'parts' => count((array) ($reader->manifest()['parts'] ?? [])),
        ];
        $cursor['phase'] = 'verify';
        $cursor['step'] = 0;

        return true;
    }

    /**
     * @param array<string, mixed> $cursor
     * @param ArchiveReader|null   $reader
     * @param SliceContext         $slice
     * @return bool
     */
    private function verify(#[\SensitiveParameter] array &$cursor, ?ArchiveReader &$reader, SliceContext $slice): bool
    {
        $reader ??= $this->reader($cursor);
        $steps = $reader->verifySteps();
        while ($cursor['step'] < count($steps)) {
            $reader->verifyStep($steps[$cursor['step']]);
            $cursor['step']++;
            if ($slice->expired()) {
                return false;
            }
        }
        $cursor['phase'] = 'adopt';
        unset($cursor['dek']);

        return true;
    }

    /**
     * @param object               $job
     * @param array<string, mixed> $cursor
     * @param SliceContext         $slice
     * @return bool
     * @throws LeaseLost
     */
    private function adopt(object $job, #[\SensitiveParameter] array &$cursor, SliceContext $slice): bool
    {
        $id = (string) $cursor['archive_id'];
        if ($this->archives->find($id) !== null) {
            throw new RestoreRefused(RestoreRefused::ALREADY_IN_CATALOGUE, $id);
        }
        $inVault = !empty($cursor['in_vault']);
        // A slice that died right after the move left the folder in the vault: go on from there.
        $moved = !empty($cursor['adopting']) && !is_dir((string) $cursor['dir']) && is_dir($this->vault->archiveDir($id));
        if (!$inVault && !$moved) {
            $cursor['adopting'] = true;
            $slice->save($cursor);
            $this->vault->adopt((string) $cursor['dir'], $id);
        }
        $meta = (array) $cursor['meta'];
        $date = self::date((string) ($meta['created_at'] ?? ''));
        try {
            DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($job, $slice, $cursor, $id, $meta, $date) {
                $this->archives->create([
                    'id' => $id,
                    'kind' => (string) $meta['kind'],
                    'reason' => Archive::REASON_IMPORT,
                    'scope' => $meta['scope'] === null ? null : json_encode($meta['scope'], JSON_UNESCAPED_SLASHES),
                    'status' => Archive::STATUS_OK,
                    'consistency' => $meta['consistency'],
                    'origin' => (string) $cursor['origin'],
                    // Fetched from the external destination: it is there already, not copied again.
                    'offsite' => !empty($cursor['remote']) ? Archive::OFFSITE_DONE : Archive::OFFSITE_NONE,
                    'key_id' => (string) $cursor['key_id'],
                    'size' => $this->vault->archiveBytes($id),
                    'parts' => (int) $meta['parts'],
                    'created_by' => $cursor['created_by'] ?? null,
                    'finished_at' => now(),
                    'verified_at' => now(),
                    'created_at' => $date,
                    'updated_at' => now(),
                ]);
                if (!$this->jobs->attachArchive((int) $job->id, $slice->owner, $id) || !$this->jobs->finish((int) $job->id, $slice->owner, Archive::JOB_DONE)) {
                    throw new LeaseLost;
                }
            });
        } catch (\Throwable $e) {
            // The catalogue row was not written: an imported copy goes again (a folder that was
            // already in the vault stays as it was).
            if (!$inVault) {
                $this->vault->deleteArchive($id);
            }

            throw $e;
        }
        $this->dropSource($cursor);
        BackupAudit::record('import', BackupAudit::OK, ['archive_id' => $id, 'origin' => (string) $cursor['origin']]);
        $cursor['phase'] = 'done';

        return true;
    }

    /**
     * @param array<string, mixed> $cursor
     * @return ArchiveReader Opened with the data key unwrapped when the import started.
     * @throws ArchiveRejected
     */
    private function reader(#[\SensitiveParameter] array &$cursor): ArchiveReader
    {
        $dir = (string) $cursor['dir'];
        $dek = (string) base64_decode((string) ($cursor['dek'] ?? ''), true);
        try {
            $signingKey = $this->keys->signingKey();
        } catch (\Throwable $e) {
            // A damaged configuration (recovery on a broken site): the signature cannot be checked.
            $signingKey = null;
        }
        $cursor['origin'] = ArchiveReader::originOf($dir, $signingKey);
        $reader = ArchiveReader::openWithDataKey($dir, $dek, $signingKey, $cursor['origin']);
        $header = $reader->header();
        if (!hash_equals((string) $cursor['archive_id'], (string) $header['archive_id'])
            || !hash_equals((string) $cursor['key_id'], (string) $header['key_id'])
            || CanonicalJson::encode((array) $cursor['dek_wrapped']) !== CanonicalJson::encode($header['dek'])) {
            throw new ArchiveRejected(ArchiveRejected::KEY_MISMATCH, 'header changed');
        }

        return $reader;
    }

    /**
     * @param object               $job
     * @param SliceContext         $slice
     * @param array<string, mixed> $cursor
     * @param string               $reason
     * @return void
     * @throws LeaseLost
     */
    private function failWith(object $job, SliceContext $slice, #[\SensitiveParameter] array $cursor, string $reason): void
    {
        if (!$this->jobs->finish((int) $job->id, $slice->owner, Archive::JOB_FAILED, 'import: ' . $reason)) {
            throw new LeaseLost;
        }
        $this->abort($cursor);
        BackupAudit::record('import', BackupAudit::FAILED, ['reason' => $reason]);
        BackupLog::failure('Import job ' . $job->id . ' failed: ' . $reason);
    }

    /**
     * @param array<string, mixed> $cursor
     * @return void
     */
    private function dropSource(#[\SensitiveParameter] array $cursor): void
    {
        // WHY only "owned": a file named on the command line belongs to the person, never removed.
        if (!empty($cursor['owned']) && is_string($cursor['source'] ?? null) && is_file($cursor['source'])) {
            @unlink($cursor['source']);
        }
    }

    /**
     * @param mixed $scope Scope from the archive's manifest (data from outside this site).
     * @return array<string, mixed>|null Only the known keys, with scalar / string-list values.
     */
    private static function scope(mixed $scope): ?array
    {
        if (!is_array($scope)) {
            return null;
        }
        $out = [];
        foreach (['kind', 'group', 'exclude_logs'] as $key) {
            if (isset($scope[$key]) && is_scalar($scope[$key])) {
                $out[$key] = is_string($scope[$key]) ? mb_substr($scope[$key], 0, 100) : $scope[$key];
            }
        }
        foreach (['groups', 'files', 'resolved_groups', 'file_groups'] as $key) {
            if (isset($scope[$key]) && is_array($scope[$key])) {
                $out[$key] = array_slice(array_values(array_filter($scope[$key], fn ($v) => is_string($v) && strlen($v) <= 100)), 0, 200);
            }
        }

        return $out;
    }

    /**
     * @param string $value ISO 8601 date of the archive.
     * @return Carbon
     */
    private static function date(string $value): Carbon
    {
        try {
            $date = Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable $e) {
            return now();
        }

        return $date->isFuture() ? now() : $date;
    }
}
