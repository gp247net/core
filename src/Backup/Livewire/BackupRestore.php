<?php

namespace GP247\Core\Backup\Livewire;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\Backup\Application\BackupAccess;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\BackupFeature;
use GP247\Core\Backup\Application\BackupNotifier;
use GP247\Core\Backup\Application\DashboardStatus;
use GP247\Core\Backup\Application\DataGroupRegistry;
use GP247\Core\Backup\Application\ImportService;
use GP247\Core\Backup\Application\OffsiteService;
use GP247\Core\Backup\Application\RestoreService;
use GP247\Core\Backup\Application\UploadSession;
use GP247\Core\Backup\Http\UploadController;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Domain\BackupSecret;
use GP247\Core\Backup\Domain\RestorePlan;
use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Domain\WrongBackupSecret;
use GP247\Core\Backup\Livewire\Concerns\ConfirmsStepUp;
use GP247\Core\Backup\Livewire\Concerns\RendersNotices;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The restore screen (R0 replace a group / table · R0 add back deleted records · R1 configuration ·
 * R2 whole database): choose an archive and a level, type the backup password or recovery key,
 * re-confirm the sign-in, read the preview (data that would be lost, columns, version, accounts),
 * type the confirmation, then follow the job; undo while the old tables are kept.
 *
 * The preview is kept in the session, not in a public property: it can hold sample rows of site
 * data, which must not travel in the component snapshot nor reach the operation log.
 *
 * @aidlc-unit backup-admin
 * BK-5: a `.gp247bak` file can be uploaded (in pieces, then imported with the password of the
 * site that made it); an archive whose origin cannot be verified needs its own confirmation; the
 * whole-database level offers the post-incident mode.
 *
 * BK-6: archives at the external destination can be listed and brought back (fetch + import).
 *
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-deleted-records, US-backup-restore-config-restore, US-backup-restore-full-database, US-backup-admin-step-up-auth, US-backup-restore-import-archive, US-backup-restore-post-incident-mode, US-backup-storage-fetch-remote
 * @aidlc-adr backup-restore_shadow-table-swap
 */
class BackupRestore extends Component
{
    use ConfirmsStepUp;
    use RendersNotices;

    private const PREVIEW_KEY = 'gp247_backup_restore_preview';
    private const PREVIEW_SECONDS = 900;

    #[Url(as: 'archive')]
    public string $archiveId = '';
    public string $level = RestorePlan::REPLACE;
    /** @var list<string> */
    public array $groups = [];
    public string $undeleteTable = '';
    /** @var list<string> */
    public array $undeleteKeys = [];
    public bool $undeleteAll = false;

    public string $restoreSecret = '';
    public bool $restoreWithRecovery = false;
    public string $confirmText = '';
    /** The person confirmed restoring an archive whose origin cannot be verified. */
    public bool $acceptUnverified = false;
    /** Whole database only: end sessions, revoke tokens, require new passwords afterwards. */
    public bool $postIncident = false;

    /** Fingerprint of the plan the preview in the session belongs to. */
    public ?string $previewFor = null;
    public ?int $jobId = null;
    /** @var list<array<string, mixed>>|null BK-6: archives found at the external destination (names, sizes, times only). */
    #[Locked]
    public ?array $remoteArchives = null;

    /**
     * @return void
     * @throws AuthorizationException
     */
    public function mount(): void
    {
        $this->authorizeRestore('screen');
    }

    /**
     * Any change of the plan drops the preview (the keys to add back are chosen after it).
     *
     * @param string $property
     * @return void
     */
    public function updated(string $property): void
    {
        if (in_array(explode('.', $property)[0], ['archiveId', 'level', 'groups', 'undeleteTable', 'acceptUnverified', 'postIncident'], true)) {
            $this->forgetPreview();
        }
    }

    /**
     * @return void
     */
    public function preview(): void
    {
        $this->authorizeRestore('restore_preview');
        if (!$this->requireStepUp('preview')) {
            return;
        }
        try {
            $plan = $this->plan(forPreview: true);
            $preview = $this->service()->preview($plan, $this->secret(), $this->operator(), $this->options());
        } catch (RestoreRefused $e) {
            $this->refused('restore_preview', $e->reason, ['archive_id' => $this->archiveId, 'level' => $this->level]);

            return;
        } catch (WrongBackupSecret $e) {
            $this->refused('restore_preview', 'wrong_secret', ['archive_id' => $this->archiveId]);

            return;
        } catch (ArchiveRejected $e) {
            $this->refused('restore_preview', 'archive_rejected', ['archive_id' => $this->archiveId]);

            return;
        }
        $this->previewFor = $this->fingerprint();
        session()->put(self::PREVIEW_KEY, ['for' => $this->previewFor, 'until' => time() + self::PREVIEW_SECONDS, 'data' => $preview]);
        $this->confirmText = '';
        $this->notice = null;
        BackupAudit::record('restore_preview', BackupAudit::OK, ['archive_id' => $this->archiveId, 'level' => $this->level]);
    }

    /**
     * Start the restore of the previewed plan.
     *
     * @return void
     */
    public function start(): void
    {
        $this->authorizeRestore('restore');
        if (!$this->requireStepUp('start')) {
            return;
        }
        if ($this->previewData() === null) {
            $this->notice('admin.backup.restore.preview_first', 'error');

            return;
        }
        $this->resetErrorBag('confirmText');
        if (strtoupper(trim($this->confirmText)) !== substr($this->archiveId, -6)) {
            $this->addError('confirmText', gp247_language_render('admin.backup.restore.confirm_wrong'));

            return;
        }
        $admin = admin()->user();
        try {
            $started = $this->service()->start($this->plan(), $this->secret(), [
                'operator' => $this->operator(),
                'created_by' => (string) $admin->username,
            ] + $this->options());
        } catch (RestoreRefused $e) {
            $this->refused('restore', $e->reason, ['archive_id' => $this->archiveId, 'level' => $this->level]);

            return;
        } catch (WrongBackupSecret $e) {
            $this->refused('restore', 'wrong_secret', ['archive_id' => $this->archiveId]);

            return;
        } catch (ArchiveRejected $e) {
            $this->refused('restore', 'archive_rejected', ['archive_id' => $this->archiveId]);

            return;
        }
        $this->restoreSecret = '';
        $this->confirmText = '';
        $this->jobId = (int) $started['job_id'];
        if (is_string($started['maintenance_secret'] ?? null) && $started['maintenance_secret'] !== '') {
            // WHY: the person restoring the whole database keeps using the site while it is in
            // maintenance; the secret goes out only inside this cookie, never in a property.
            Cookie::queue(MaintenanceModeBypassCookie::create($started['maintenance_secret']));
        }
        $this->forgetPreview();
        BackupAudit::record('restore', BackupAudit::OK, ['archive_id' => $this->archiveId, 'level' => $this->level, 'job_id' => $this->jobId]);
        app(BackupNotifier::class)->restoreStarted($this->archiveId, $this->level, (string) $admin->username);
        $this->notice('admin.backup.restore.started');
        $this->dispatch('gp247-backup-run');
    }

    /**
     * Begin an upload of a `.gp247bak` file; the browser then sends it in pieces to the upload
     * route and calls importUpload().
     *
     * @param int $size Bytes of the chosen file.
     * @return array{id: string, url: string, chunk: int}|null Null when refused (notice shown) or
     *                                                          when the sign-in must be re-confirmed first.
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function prepareUpload(int $size): ?array
    {
        $this->authorizeRestore('upload');
        if (!$this->requireStepUp('prepareUpload', [$size])) {
            return null;
        }
        try {
            $id = (new UploadSession)->begin($size, (string) admin()->user()->id, (string) session()->getId());
        } catch (RestoreRefused $e) {
            $this->refused('upload', $e->reason);

            return null;
        }
        BackupAudit::record('upload', BackupAudit::OK, ['size' => $size]);

        return ['id' => $id, 'url' => gp247_route_admin(UploadController::ROUTE, ['upload' => $id]), 'chunk' => UploadSession::chunkBytes()];
    }

    /**
     * The upload is complete: open it with the password / recovery key typed above (of the site
     * that made it) and start the import job (unpack, signature or "unverified", full check).
     *
     * @param string $uploadId
     * @return void
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function importUpload(string $uploadId): void
    {
        $this->authorizeRestore('import');
        if (!$this->requireStepUp('importUpload', [$uploadId])) {
            return;
        }
        $uploads = new UploadSession;
        $adminId = (string) admin()->user()->id;
        try {
            $path = $uploads->complete($uploadId, $adminId);
            $started = (new ImportService)->start($path, true, $this->secret(), ['created_by' => (string) admin()->user()->username]);
        } catch (RestoreRefused $e) {
            // A wrong / busy moment keeps the upload for another try; a file that can never be
            // imported goes (ImportService already removed it).
            if ($e->reason === RestoreRefused::ALREADY_IN_CATALOGUE) {
                $uploads->discard($uploadId, $adminId);
            }
            $this->refused('import', $e->reason);

            return;
        } catch (WrongBackupSecret $e) {
            $this->refused('import', 'wrong_secret');

            return;
        } catch (ArchiveRejected $e) {
            $uploads->discard($uploadId, $adminId);
            $this->refused('import', 'archive_rejected');

            return;
        }
        $uploads->forget($uploadId);
        $this->restoreSecret = '';
        $this->jobId = (int) $started['job_id'];
        BackupAudit::record('import_start', BackupAudit::OK, ['archive_id' => $started['archive_id'], 'job_id' => $this->jobId]);
        $this->notice('admin.backup.import.started');
        $this->dispatch('gp247-backup-run');
    }

    /**
     * List this site's archives at the external destination — names, sizes and times only, no
     * backup password needed (US-backup-storage-fetch-remote).
     *
     * @return void
     *
     * @aidlc-unit backup-storage
     * @aidlc-story US-backup-storage-fetch-remote
     */
    public function listRemote(): void
    {
        $this->authorizeRestore('remote_list');
        try {
            $this->remoteArchives = array_map(fn ($a) => $a->toArray(), (new OffsiteService)->remoteArchives());
        } catch (DestinationFailure $e) {
            $this->remoteArchives = null;
            BackupAudit::record('remote_list', BackupAudit::FAILED, ['reason' => $e->reason]);
            $this->notice('admin.backup.offsite.error.' . $e->reason, 'error');

            return;
        }
        BackupAudit::record('remote_list', BackupAudit::OK, ['status' => (string) count($this->remoteArchives)]);
    }

    /**
     * Bring one archive back from the external destination with the password / recovery key typed
     * above; it is copied in slices, then checked like any import before it can be restored.
     *
     * @param string $archiveId
     * @return void
     *
     * @aidlc-unit backup-storage
     * @aidlc-story US-backup-storage-fetch-remote
     */
    public function fetchRemote(string $archiveId): void
    {
        $this->authorizeRestore('fetch');
        if (!$this->requireStepUp('fetchRemote', [$archiveId])) {
            return;
        }
        try {
            $started = (new ImportService)->startFromRemote($archiveId, $this->secret(), ['created_by' => (string) admin()->user()->username]);
        } catch (RestoreRefused $e) {
            $this->refused('fetch', $e->reason, ['archive_id' => $archiveId]);

            return;
        } catch (WrongBackupSecret $e) {
            $this->refused('fetch', 'wrong_secret', ['archive_id' => $archiveId]);

            return;
        } catch (ArchiveRejected $e) {
            $this->refused('fetch', 'archive_rejected', ['archive_id' => $archiveId]);

            return;
        } catch (DestinationFailure $e) {
            BackupAudit::record('fetch', BackupAudit::FAILED, ['archive_id' => $archiveId, 'reason' => $e->reason]);
            $this->notice('admin.backup.offsite.error.' . $e->reason, 'error');

            return;
        } finally {
            $this->restoreSecret = '';
        }
        $this->jobId = (int) $started['job_id'];
        BackupAudit::record('fetch_start', BackupAudit::OK, ['archive_id' => $archiveId, 'job_id' => $this->jobId]);
        $this->notice('admin.backup.remote.started');
        $this->dispatch('gp247-backup-run');
    }

    /**
     * Put back the tables the last restore replaced (while they are kept).
     *
     * @return void
     */
    public function undo(): void
    {
        $this->authorizeRestore('undo');
        if (!$this->requireStepUp('undo')) {
            return;
        }
        try {
            $undone = $this->service()->undo();
        } catch (RestoreRefused $e) {
            $this->refused('undo', $e->reason);

            return;
        }
        BackupAudit::record('undo', BackupAudit::OK, ['tables' => $undone['tables']]);
        app(BackupNotifier::class)->restoreFinished(null, 'undone');
        $this->notice('admin.backup.restore.undone');
    }

    /**
     * Stop a restore that has not replaced anything yet.
     *
     * @return void
     */
    public function cancelRestore(): void
    {
        $this->authorizeRestore('restore_cancel');
        if ($this->jobId === null) {
            return;
        }
        try {
            $this->service()->cancel($this->jobId);
        } catch (RestoreRefused $e) {
            $this->refused('restore_cancel', $e->reason, ['job_id' => $this->jobId]);

            return;
        }
        BackupAudit::record('restore_cancel', BackupAudit::OK, ['job_id' => $this->jobId]);
        $this->jobId = null;
    }

    /**
     * @return View
     * @throws AuthorizationException
     */
    public function render(): View
    {
        $this->authorizeRestore('screen');
        $service = $this->service();
        $archives = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_archive')
            ->where('status', Archive::STATUS_OK)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();
        $selected = $archives->firstWhere('id', $this->archiveId);

        return view('gp247-admin::backup.restore', [
            'enabled' => BackupFeature::enabled(),
            'archives' => $archives,
            'selected' => $selected,
            'groups' => (new DataGroupRegistry)->groups(),
            'preview' => $this->previewData(),
            'last' => $service->last(),
            'undoPoint' => $service->undoPoint(),
            'job' => (new DashboardStatus)->activeJob(),
            'lastImport' => $this->lastImport(),
            'uploadMaxBytes' => UploadSession::maxBytes(),
            'offsiteConfigured' => (new OffsiteService)->configured(),
            'stepUpNeedsCode' => $this->stepUpOpen && $this->stepUpNeedsCode(),
            'tickUrl' => gp247_route_admin('admin.backup.restore_tick'),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('admin.backup.restore.title'),
        ]);
    }

    /**
     * @return list<string>
     */
    protected function stepUpActions(): array
    {
        return ['preview', 'start', 'undo', 'prepareUpload', 'importUpload', 'fetchRemote'];
    }

    /**
     * @return array<string, bool> Options of the BK-5 confirmations, passed to preview and start.
     */
    private function options(): array
    {
        return ['accept_unverified' => $this->acceptUnverified, 'post_incident' => $this->postIncident && $this->level === RestorePlan::FULL];
    }

    /**
     * @return array{status: string, archive_id: string|null, origin: string|null, error: string|null}|null
     *         Latest import (upload or command line), for the result line.
     */
    private function lastImport(): ?array
    {
        $id = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_job')
            ->where('type', Archive::JOB_IMPORT)->orderByDesc('id')->value('id');

        return $id === null ? null : (new ImportService)->result((int) $id);
    }

    /**
     * @param bool $forPreview Undelete preview lists candidates (no keys yet).
     * @return RestorePlan
     * @throws RestoreRefused level_invalid when the form does not describe a plan.
     */
    private function plan(bool $forPreview = false): RestorePlan
    {
        if ($this->archiveId === '') {
            throw new RestoreRefused(RestoreRefused::NOT_FOUND);
        }

        return match ($this->level) {
            RestorePlan::REPLACE => RestorePlan::replace($this->archiveId, array_values($this->groups)),
            RestorePlan::UNDELETE => RestorePlan::undelete(
                $this->archiveId,
                $this->undeleteTable,
                $forPreview || $this->undeleteAll ? null : array_values(array_map('strval', $this->undeleteKeys))
            ),
            RestorePlan::CONFIG => RestorePlan::config($this->archiveId),
            RestorePlan::FULL => RestorePlan::full($this->archiveId),
            default => throw new RestoreRefused(RestoreRefused::LEVEL_INVALID),
        };
    }

    /**
     * @return BackupSecret
     */
    private function secret(): BackupSecret
    {
        return $this->restoreWithRecovery ? BackupSecret::recovery($this->restoreSecret) : BackupSecret::password($this->restoreSecret);
    }

    /**
     * @return array{id: string, username: string}
     */
    private function operator(): array
    {
        $admin = admin()->user();

        return ['id' => (string) $admin->id, 'username' => (string) $admin->username];
    }

    /**
     * @return string Plan fingerprint (keys of an undelete are chosen after the preview).
     */
    private function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->archiveId, $this->level, array_values($this->groups), $this->undeleteTable, (string) admin()->user()?->id, $this->options()]));
    }

    /**
     * @return array<string, mixed>|null Preview of the current plan, while still fresh.
     */
    private function previewData(): ?array
    {
        $stored = session()->get(self::PREVIEW_KEY);
        if (!is_array($stored) || $this->previewFor === null || !hash_equals((string) ($stored['for'] ?? ''), $this->previewFor)
            || !hash_equals($this->previewFor, $this->fingerprint()) || (int) ($stored['until'] ?? 0) < time()) {
            return null;
        }

        return (array) $stored['data'];
    }

    /**
     * @return void
     */
    private function forgetPreview(): void
    {
        session()->forget(self::PREVIEW_KEY);
        $this->previewFor = null;
    }

    /**
     * @return RestoreService
     */
    private function service(): RestoreService
    {
        return app()->bound(RestoreService::class) ? app(RestoreService::class) : new RestoreService;
    }

    /**
     * @param string $action
     * @return void
     * @throws AuthorizationException
     */
    private function authorizeRestore(string $action): void
    {
        BackupAccess::authorize(BackupAccess::RESTORE, $action);
    }
}
