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
use GP247\Core\Backup\Application\SecretAttemptLimiter;
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
use GP247\Core\Support\PreChange\PreChange;
use GP247\Core\Support\PreChange\PreChangeGate;
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
    /** Password / recovery key of the site that made the file being uploaded: its own field on the upload card. */
    public string $importSecret = '';
    public bool $importWithRecovery = false;
    /** Password / recovery key for bringing an archive back from the external destination: its own field on that card. */
    public string $remoteSecret = '';
    public bool $remoteWithRecovery = false;
    public string $confirmText = '';
    /** The person confirmed restoring an archive whose origin cannot be verified. */
    public bool $acceptUnverified = false;
    /** Whole database only: end sessions, revoke tokens, require new passwords afterwards. */
    public bool $postIncident = false;
    /** The person confirmed that columns the site no longer has lose their data (mod 20261110T164834). */
    public bool $acceptColumnLoss = false;

    /** Fingerprint of the plan the preview in the session belongs to. */
    public ?string $previewFor = null;
    public ?int $jobId = null;
    /** @var array<string, mixed>|null What undoing the last restore would drop (counts only, no row data). */
    #[Locked]
    public ?array $undoPreview = null;
    /** The code typed to confirm the undo. */
    public string $undoConfirm = '';
    /** @var list<string> Why the backup before the undo was not made (or a reminder). */
    #[Locked]
    public array $undoMessages = [];
    /** The backup before the undo failed (not "still being made"): the person may go on without it. */
    #[Locked]
    public bool $undoCanSkip = false;
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
        if (in_array(explode('.', $property)[0], ['archiveId', 'level', 'groups', 'undeleteTable', 'acceptUnverified', 'postIncident', 'acceptColumnLoss'], true)) {
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
        if ($this->secretMissing('restore_preview', $this->restoreSecret) || $this->secretThrottled('restore_preview')) {
            return;
        }
        try {
            $plan = $this->plan(forPreview: true);
            $preview = $this->service()->preview($plan, $this->secretOf($this->restoreSecret, $this->restoreWithRecovery), $this->operator(), $this->options());
            SecretAttemptLimiter::clear((string) admin()->user()->id);
        } catch (RestoreRefused $e) {
            $this->refused('restore_preview', $e->reason, ['archive_id' => $this->archiveId, 'level' => $this->level]);

            return;
        } catch (WrongBackupSecret $e) {
            SecretAttemptLimiter::failed((string) admin()->user()->id);
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
        if ($this->secretMissing('restore', $this->restoreSecret) || $this->secretThrottled('restore')) {
            return;
        }
        try {
            $started = $this->service()->start($this->plan(), $this->secretOf($this->restoreSecret, $this->restoreWithRecovery), [
                'operator' => $this->operator(),
                'created_by' => (string) $admin->username,
            ] + $this->options());
            SecretAttemptLimiter::clear((string) $admin->id);
        } catch (RestoreRefused $e) {
            $this->refused('restore', $e->reason, ['archive_id' => $this->archiveId, 'level' => $this->level]);

            return;
        } catch (WrongBackupSecret $e) {
            SecretAttemptLimiter::failed((string) $admin->id);
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
        // WHY before the upload: without the secret the import can only fail, after the whole file
        // (up to the upload ceiling) has been sent.
        if ($this->secretMissing('upload', $this->importSecret)) {
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
     * The upload is complete: open it with the password / recovery key typed on the upload card (of
     * the site that made it) and start the import job (unpack, signature or "unverified", full check).
     * The typed secret is dropped after every try.
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
        if ($this->secretMissing('import', $this->importSecret) || $this->secretThrottled('import')) {
            return;
        }
        $secret = $this->secretOf($this->importSecret, $this->importWithRecovery);
        $this->importSecret = '';
        try {
            $path = $uploads->complete($uploadId, $adminId);
            $started = (new ImportService)->start($path, true, $secret, ['created_by' => (string) admin()->user()->username]);
            SecretAttemptLimiter::clear($adminId);
        } catch (RestoreRefused $e) {
            // A wrong / busy moment keeps the upload for another try; a file that can never be
            // imported goes (ImportService already removed it).
            if ($e->reason === RestoreRefused::ALREADY_IN_CATALOGUE) {
                $uploads->discard($uploadId, $adminId);
            }
            $this->refused('import', $e->reason);

            return;
        } catch (WrongBackupSecret $e) {
            SecretAttemptLimiter::failed($adminId);
            $this->refused('import', 'wrong_secret');

            return;
        } catch (ArchiveRejected $e) {
            $uploads->discard($uploadId, $adminId);
            $this->refused('import', 'archive_rejected');

            return;
        }
        $uploads->forget($uploadId);
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
     * on that card; it is copied in slices, then checked like any import before it can be restored.
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
        if ($this->secretMissing('fetch', $this->remoteSecret) || $this->secretThrottled('fetch')) {
            return;
        }
        try {
            $started = (new ImportService)->startFromRemote($archiveId, $this->secretOf($this->remoteSecret, $this->remoteWithRecovery), ['created_by' => (string) admin()->user()->username]);
            SecretAttemptLimiter::clear((string) admin()->user()->id);
        } catch (RestoreRefused $e) {
            $this->refused('fetch', $e->reason, ['archive_id' => $archiveId]);

            return;
        } catch (WrongBackupSecret $e) {
            SecretAttemptLimiter::failed((string) admin()->user()->id);
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
            $this->remoteSecret = '';
        }
        $this->jobId = (int) $started['job_id'];
        BackupAudit::record('fetch_start', BackupAudit::OK, ['archive_id' => $archiveId, 'job_id' => $this->jobId]);
        $this->notice('admin.backup.remote.started');
        $this->dispatch('gp247-backup-run');
    }

    /**
     * Show what undoing the last restore would drop (US-backup-restore-undo-preview); nothing
     * changes until the code is typed in confirmUndo().
     *
     * @return void
     *
     * @aidlc-story US-backup-restore-undo-preview
     */
    public function undo(): void
    {
        $this->authorizeRestore('undo');
        if (!$this->requireStepUp('undo')) {
            return;
        }
        $this->resetUndo();
        try {
            $this->undoPreview = $this->service()->undoPreview();
        } catch (RestoreRefused $e) {
            $this->refused('undo', $e->reason);

            return;
        }
        BackupAudit::record('undo_preview', BackupAudit::OK, ['tables' => array_column($this->undoPreview['tables'], 'table')]);
    }

    /**
     * Undo after the preview and the typed code: the tables put back are saved first in a
     * "before a change" backup (`restore.undo`), unless that failed and the person chose to go on.
     *
     * @param bool $withoutSnapshot Go on although the backup before the undo could not be made.
     * @return void
     *
     * @aidlc-story US-backup-restore-undo-preview
     */
    public function confirmUndo(bool $withoutSnapshot = false): void
    {
        $this->authorizeRestore('undo');
        if (!$this->requireStepUp('confirmUndo', [$withoutSnapshot])) {
            return;
        }
        $preview = $this->undoPreview;
        $point = $this->service()->undoPoint();
        if ($preview === null || $point === null || (int) ($point['job_id'] ?? 0) !== (int) ($preview['job_id'] ?? -1)) {
            // WHY: another restore (or undo) happened since the preview — what was shown is no longer true.
            $this->resetUndo();
            $this->notice('admin.backup.restore.undo_preview_first', 'error');

            return;
        }
        $this->resetErrorBag('undoConfirm');
        if (strtoupper(trim($this->undoConfirm)) !== (string) $preview['code']) {
            $this->addError('undoConfirm', gp247_language_render('admin.backup.restore.confirm_wrong'));

            return;
        }
        $tables = array_column($preview['tables'], 'table');
        if (!$withoutSnapshot) {
            $outcome = (new PreChangeGate)->run(new PreChange(PreChange::RESTORE_UNDO, ['tables' => $tables, 'restore' => (int) $preview['job_id']]));
            $this->undoMessages = array_values(array_merge($outcome->reasons(), $outcome->notices()));
            if (!$outcome->proceed()) {
                // A backup still being made is finished by the beacon: try again, not "go on without".
                $this->undoCanSkip = !collect($outcome->toArray())->contains(fn ($entry) => !empty($entry['pending_job']));

                return;
            }
        } elseif (!$this->undoCanSkip) {
            return;
        }
        try {
            $undone = $this->service()->undo();
        } catch (RestoreRefused $e) {
            $this->refused('undo', $e->reason);

            return;
        }
        BackupAudit::record('undo', BackupAudit::OK, ['tables' => $undone['tables'], 'status' => $withoutSnapshot ? 'without_snapshot' : 'with_snapshot']);
        app(BackupNotifier::class)->restoreFinished(null, 'undone');
        $this->resetUndo();
        $this->notice('admin.backup.restore.undone');
    }

    /**
     * The backup before the undo could not be made and the person goes on without it.
     *
     * @return void
     *
     * @aidlc-story US-backup-restore-undo-preview
     */
    public function undoWithoutSnapshot(): void
    {
        $this->confirmUndo(true);
    }

    /**
     * @return void
     */
    public function cancelUndo(): void
    {
        $this->resetUndo();
    }

    /**
     * @return void
     */
    private function resetUndo(): void
    {
        $this->undoPreview = null;
        $this->undoConfirm = '';
        $this->undoMessages = [];
        $this->undoCanSkip = false;
        $this->resetErrorBag('undoConfirm');
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
            'dataGroups' => (new DataGroupRegistry)->groups(),
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
        return ['preview', 'start', 'undo', 'confirmUndo', 'prepareUpload', 'importUpload', 'fetchRemote'];
    }

    /**
     * @return array<string, bool> Options of the BK-5 confirmations, passed to preview and start.
     */
    private function options(): array
    {
        return [
            'accept_unverified' => $this->acceptUnverified,
            'post_incident' => $this->postIncident && $this->level === RestorePlan::FULL,
            'accept_column_loss' => $this->acceptColumnLoss,
            // Mod 20261110T164834 (D1): an archive of unverified origin needs an Administrator here.
            'via' => 'web',
            'administrator' => BackupAccess::isAdministrator(),
        ];
    }

    /**
     * An empty secret field is refused with its own message: it is not a wrong password, so it is
     * neither reported as one nor counted against the attempt limit.
     *
     * @param string $action Audit action.
     * @param string $value  What the field of that card holds.
     * @return bool The backup password / recovery key field is empty: refused.
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    private function secretMissing(string $action, #[\SensitiveParameter] string $value): bool
    {
        if ($value !== '') {
            return false;
        }
        $this->refused($action, 'secret_required', ['archive_id' => $this->archiveId]);

        return true;
    }

    /**
     * @param string $action Audit action.
     * @return bool Too many wrong backup passwords lately: refused (and the typed secret dropped).
     *
     * @aidlc-story US-backup-admin-step-up-auth
     */
    private function secretThrottled(string $action): bool
    {
        if (SecretAttemptLimiter::begin((string) admin()->user()->id) === null) {
            return false;
        }
        $this->restoreSecret = '';
        $this->importSecret = '';
        $this->remoteSecret = '';
        $this->refused($action, SecretAttemptLimiter::REASON, ['archive_id' => $this->archiveId]);

        return true;
    }

    /**
     * Latest import (upload or command line), for the result line. `restored` tells whether a
     * restore from that archive started after it: until then the screen says that bringing a file
     * in changed nothing on the site and offers to restore from it.
     *
     * @return array{status: string, archive_id: string|null, origin: string|null, error: string|null, restored: bool}|null
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    private function lastImport(): ?array
    {
        $jobs = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_job');
        $id = (clone $jobs)->where('type', Archive::JOB_IMPORT)->orderByDesc('id')->value('id');
        if ($id === null) {
            return null;
        }
        $result = (new ImportService)->result((int) $id);
        $result['restored'] = $result['archive_id'] !== null && (clone $jobs)->where('type', Archive::JOB_RESTORE)
            ->where('archive_id', $result['archive_id'])->where('id', '>', (int) $id)->exists();

        return $result;
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
     * @param string $value    What the person typed.
     * @param bool   $recovery The "use the recovery key instead" box of the same card is ticked.
     * @return BackupSecret
     */
    private function secretOf(#[\SensitiveParameter] string $value, bool $recovery): BackupSecret
    {
        return $recovery ? BackupSecret::recovery($value) : BackupSecret::password($value);
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
