<?php

namespace GP247\Core\Backup\Http;

use GP247\Core\Backup\Application\BackupAccess;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\BackupFeature;
use GP247\Core\Backup\Application\UploadSession;
use GP247\Core\Backup\Domain\RestoreRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives one piece of a `.gp247bak` upload (POST <admin>/backup/restore/upload/{upload}). The
 * upload was begun by the restore screen right after the sign-in was re-confirmed; every piece
 * checks again: feature on, restore right, upload bound to this admin and to the browser session
 * that began it, offset = bytes received so far (otherwise 409 with the offset to resend from).
 * Body = raw bytes. Importing the finished upload asks for a fresh re-confirmation again.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-import-archive
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
class UploadController
{
    public const ROUTE = 'admin.backup.restore_upload';

    /**
     * @param Request $request
     * @param string  $upload Upload id.
     * @return JsonResponse
     */
    public function __invoke(Request $request, string $upload): JsonResponse
    {
        if (!BackupFeature::enabled()) {
            return response()->json(['error' => 'feature_disabled'], 403);
        }
        if (!BackupAccess::allows(BackupAccess::RESTORE)) {
            BackupAudit::record('upload', BackupAudit::DENIED, ['right' => BackupAccess::RESTORE]);

            return response()->json(['error' => 'denied'], 403);
        }
        $offset = filter_var($request->header('X-Upload-Offset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($offset === false) {
            return response()->json(['error' => 'upload_offset'], 422);
        }
        try {
            $received = (new UploadSession)->append($upload, $offset, (string) $request->getContent(), (string) admin()->user()->id, (string) $request->session()->getId());
        } catch (RestoreRefused $e) {
            return response()->json(['error' => $e->reason, 'received' => (int) ($e->context['received'] ?? 0)], match ($e->reason) {
                RestoreRefused::UPLOAD_OFFSET => 409,
                RestoreRefused::UPLOAD_TOO_LARGE => 413,
                default => 404,
            });
        }

        return response()->json(['received' => $received]);
    }
}
