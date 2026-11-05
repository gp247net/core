<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\BackupRefused;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Short-lived, single-use download links. The URL carries only a random token (no archive id,
 * no file name) and a signature; the database keeps its SHA-256, the archive and the admin who
 * asked. Using a link marks it used in one conditional UPDATE, so two requests racing on the
 * same link cannot both download.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-signed-download
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
final class DownloadTokenService
{
    public const ROUTE = 'admin.backup.download';

    public const UNKNOWN = 'unknown';
    public const NOT_OWNER = 'not_owner';
    public const USED = 'used';
    public const EXPIRED = 'expired';

    /**
     * @param ArchiveRepository|null $archives
     */
    public function __construct(private ?ArchiveRepository $archives = null)
    {
        $this->archives ??= new ArchiveRepository;
    }

    /**
     * @param string $archiveId
     * @param string $adminId Admin the link is for.
     * @return string Signed URL, valid for the link lifetime.
     * @throws BackupRefused not_found when the archive is not a verified one.
     */
    public function issue(string $archiveId, string $adminId): string
    {
        $archive = $this->archives->find($archiveId);
        if ($archive === null || $archive->status !== Archive::STATUS_OK) {
            throw new BackupRefused(BackupRefused::NOT_FOUND);
        }
        $token = bin2hex(random_bytes(32));
        $seconds = self::seconds();
        $table = $this->table();
        // Lazy housekeeping: links are useless an hour after they expire.
        $table->clone()->where('expires_at', '<', now()->subHour())->delete();
        $table->insert([
            'token_hash' => hash('sha256', $token),
            'archive_id' => $archiveId,
            'admin_id' => $adminId,
            'expires_at' => now()->addSeconds($seconds),
            'created_at' => now(),
        ]);

        return URL::temporarySignedRoute(self::ROUTE, now()->addSeconds($seconds), ['token' => $token]);
    }

    /**
     * Use a link once.
     *
     * @param string $token
     * @param string $adminId Admin signed in now.
     * @return array{archive_id: string|null, reason: string|null}
     */
    public function consume(#[\SensitiveParameter] string $token, string $adminId): array
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            return ['archive_id' => null, 'reason' => self::UNKNOWN];
        }
        $row = $this->table()->where('token_hash', hash('sha256', $token))->first();
        $reason = match (true) {
            $row === null => self::UNKNOWN,
            !hash_equals((string) $row->admin_id, $adminId) => self::NOT_OWNER,
            $row->used_at !== null => self::USED,
            $row->expires_at === null || now()->greaterThanOrEqualTo($row->expires_at) => self::EXPIRED,
            default => null,
        };
        if ($reason !== null) {
            return ['archive_id' => $row->archive_id ?? null, 'reason' => $reason];
        }
        $taken = $this->table()->where('id', $row->id)->whereNull('used_at')->where('admin_id', $adminId)
            ->where('expires_at', '>', now())->update(['used_at' => now()]);

        return $taken === 1
            ? ['archive_id' => (string) $row->archive_id, 'reason' => null]
            : ['archive_id' => (string) $row->archive_id, 'reason' => self::USED];
    }

    /**
     * @return int Link lifetime in seconds (at most 5 minutes).
     */
    public static function seconds(): int
    {
        return max(30, min(300, (int) config('gp247-config.backup.download_link_seconds', 300)));
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function table()
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_download_token');
    }
}
