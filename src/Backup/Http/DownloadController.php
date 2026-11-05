<?php

namespace GP247\Core\Backup\Http;

use GP247\Core\Backup\Application\BackupAccess;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\BackupFeature;
use GP247\Core\Backup\Application\BackupNotifier;
use GP247\Core\Backup\Application\DownloadTokenService;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Infrastructure\Format\ArchivePacker;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a backup archive as one encrypted `.gp247bak` file through a signed, single-use link
 * made for the admin signed in now. Every refusal answers the same neutral page (the reason goes
 * to the audit log only), so a leaked link tells nothing to whoever holds it. The permission is
 * checked here too: "view all" passes every GET in the middleware, but never this one.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-signed-download, US-backup-admin-permissions
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
class DownloadController
{
    /**
     * @param Request $request
     * @param string  $token   Random link token from the URL.
     * @return Response
     */
    public function __invoke(Request $request, string $token): Response
    {
        $admin = admin()->user();
        if ($admin === null || !BackupAccess::allows(BackupAccess::DOWNLOAD)) {
            return $this->refuse(BackupAudit::DENIED, 'permission');
        }
        if (!$request->hasValidSignature()) {
            return $this->refuse(BackupAudit::REFUSED, 'signature');
        }
        if (!BackupFeature::enabled()) {
            return $this->refuse(BackupAudit::REFUSED, 'feature_disabled');
        }
        $used = (new DownloadTokenService)->consume($token, (string) $admin->id);
        if ($used['reason'] !== null) {
            return $this->refuse(BackupAudit::REFUSED, $used['reason'], $used['archive_id']);
        }
        $archive = (new ArchiveRepository)->find((string) $used['archive_id']);
        if ($archive === null || $archive->status !== Archive::STATUS_OK) {
            return $this->refuse(BackupAudit::REFUSED, 'archive_not_ready', $used['archive_id']);
        }
        try {
            $packer = new ArchivePacker(app(LocalVault::class)->archiveDir((string) $archive->id));
        } catch (\Throwable $e) {
            return $this->refuse(BackupAudit::FAILED, 'archive_files', (string) $archive->id);
        }

        BackupAudit::record('download', BackupAudit::OK, ['archive_id' => (string) $archive->id, 'kind' => (string) $archive->kind]);
        app(BackupNotifier::class)->downloaded($archive, (string) $admin->username);

        return response()->stream(static function () use ($packer): void {
            $packer->stream(static function (string $bytes): void {
                echo $bytes;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $packer->size(),
            'Content-Disposition' => 'attachment; filename="' . self::fileName($archive) . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param object $archive
     * @return string ASCII file name: site-kind-date.gp247bak
     */
    public static function fileName(object $archive): string
    {
        $site = Str::slug((string) (gp247_store_info('title', null, GP247_STORE_ID_ROOT) ?: config('app.name', 'site'))) ?: 'site';
        $kind = preg_replace('/[^a-z]/', '', (string) $archive->kind) ?: 'backup';

        return mb_substr($site, 0, 40) . '-' . $kind . '-' . date('Ymd-Hi', strtotime((string) $archive->created_at) ?: time()) . '.gp247bak';
    }

    /**
     * @param string      $result
     * @param string      $reason
     * @param string|null $archiveId
     * @return Response
     */
    private function refuse(string $result, string $reason, ?string $archiveId = null): Response
    {
        BackupAudit::record('download', $result, ['reason' => $reason, 'archive_id' => $archiveId]);

        return response()->view('gp247-admin::backup.link-invalid', [], 403)->header('Cache-Control', 'no-store, private');
    }
}
