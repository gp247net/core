<?php

namespace GP247\Core\Backup\Livewire;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\Backup\Application\ArchiveActions;
use GP247\Core\Backup\Application\BackupAccess;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\BackupFeature;
use GP247\Core\Backup\Application\BackupService;
use GP247\Core\Backup\Application\DashboardStatus;
use GP247\Core\Backup\Application\DataGroupRegistry;
use GP247\Core\Backup\Application\PostIncidentChecklist;
use GP247\Core\Backup\Application\DownloadTokenService;
use GP247\Core\Backup\Application\KeyService;
use GP247\Core\Backup\Application\OffsiteService;
use GP247\Core\Backup\Application\ScheduleClock;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Domain\BackupKeyError;
use GP247\Core\Backup\Domain\BackupNotSetUp;
use GP247\Core\Backup\Domain\BackupRefused;
use GP247\Core\Backup\Domain\BackupScope;
use GP247\Core\Backup\Domain\BackupSecret;
use GP247\Core\Backup\Domain\OffsiteRefused;
use GP247\Core\Backup\Domain\WrongBackupSecret;
use GP247\Core\Backup\Livewire\Concerns\ConfirmsStepUp;
use GP247\Core\Backup\Livewire\Concerns\RendersNotices;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The backup screen: first-time set-up of the backup password and recovery key, how well the
 * site is protected (last verified archive, warnings), the archive list, and the actions each
 * right allows — create (snapshot / selective / full), check again, pin, delete; download
 * (re-confirmation + single-use link); restore (link to the restore screen). Every action checks
 * its right in the server; a running job is driven by the browser through the tick endpoint.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-dashboard, US-backup-admin-permissions, US-backup-engine-key-setup
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
class BackupDashboard extends Component
{
    use WithPagination;
    use ConfirmsStepUp;
    use RendersNotices;

    private const PER_PAGE = 20;

    public string $snapshotGroup = BackupScope::DEFAULT_SNAPSHOT_GROUP;
    /** @var list<string> */
    public array $selectiveGroups = [];
    /** @var list<string> */
    public array $selectiveFiles = [];
    public bool $excludeLogs = false;
    /** Kind waiting for the "larger than the web limit" confirmation. */
    public ?string $confirmKind = null;

    public string $setupPassword = '';
    public string $setupPasswordConfirm = '';
    /** Recovery key shown once right after set-up; cleared when confirmed. */
    public ?string $shownRecoverySecret = null;
    public string $recoverySecretCheck = '';

    public ?string $verifyArchiveId = null;
    public string $verifySecret = '';
    public bool $verifyWithRecovery = false;

    public ?string $deleteArchiveId = null;
    public string $deleteConfirm = '';

    /**
     * @return void
     * @throws AuthorizationException When the admin holds no backup right.
     */
    public function mount(): void
    {
        $this->authorizeScreen();
    }

    /**
     * First set-up: backup password (≥ 12 characters, typed twice), then the recovery key shown once.
     *
     * @return void
     */
    public function setupKey(): void
    {
        $this->authorizeAdministrator('key_setup');
        $keys = new KeyService;
        if ($keys->isSetUp()) {
            return;
        }
        $this->validate([
            'setupPassword' => ['required', 'string', 'min:' . KeyService::MIN_PASSWORD_LENGTH, 'max:1024'],
            'setupPasswordConfirm' => ['required', 'same:setupPassword'],
        ], [], [
            'setupPassword' => gp247_language_render('admin.backup.setup.password'),
            'setupPasswordConfirm' => gp247_language_render('admin.backup.setup.password_again'),
        ]);
        try {
            $recovery = $keys->setup($this->setupPassword);
        } catch (BackupKeyError $e) {
            $this->notice('admin.backup.setup.failed', 'error');
            BackupAudit::record('key_setup', BackupAudit::FAILED);

            return;
        } finally {
            $this->setupPassword = '';
            $this->setupPasswordConfirm = '';
        }
        $this->shownRecoverySecret = $recovery->display();
        BackupAudit::record('key_setup', BackupAudit::OK);
    }

    /**
     * The owner proves the recovery key was written down: the last 8 characters typed back.
     *
     * @return void
     */
    public function confirmRecoveryKey(): void
    {
        $this->authorizeAdministrator('key_setup');
        $this->resetErrorBag('recoverySecretCheck');
        if ($this->shownRecoverySecret === null) {
            return;
        }
        $expected = substr(self::normalizeKey($this->shownRecoverySecret), -8);
        if (!hash_equals($expected, substr(self::normalizeKey($this->recoverySecretCheck), -8)) || strlen(self::normalizeKey($this->recoverySecretCheck)) < 8) {
            $this->addError('recoverySecretCheck', gp247_language_render('admin.backup.setup.check_wrong'));

            return;
        }
        $this->shownRecoverySecret = null;
        $this->recoverySecretCheck = '';
        $this->notice('admin.backup.setup.done');
    }

    /**
     * @return void
     */
    public function createSnapshot(): void
    {
        $this->create(Archive::KIND_SNAPSHOT, fn () => BackupScope::snapshot($this->snapshotGroup));
    }

    /**
     * @return void
     */
    public function createSelective(): void
    {
        $this->create(Archive::KIND_SELECTIVE, fn () => BackupScope::selective(array_values($this->selectiveGroups), array_values($this->selectiveFiles)));
    }

    /**
     * @return void
     */
    public function createFull(): void
    {
        $this->create(Archive::KIND_FULL, fn () => BackupScope::full($this->excludeLogs));
    }

    /**
     * Confirm a backup larger than the web limit (the CLI is recommended for those).
     *
     * @return void
     */
    public function confirmLarge(): void
    {
        $kind = $this->confirmKind;
        $this->confirmKind = null;
        match ($kind) {
            Archive::KIND_SNAPSHOT => $this->create($kind, fn () => BackupScope::snapshot($this->snapshotGroup), true),
            Archive::KIND_SELECTIVE => $this->create($kind, fn () => BackupScope::selective(array_values($this->selectiveGroups), array_values($this->selectiveFiles)), true),
            Archive::KIND_FULL => $this->create($kind, fn () => BackupScope::full($this->excludeLogs), true),
            default => null,
        };
    }

    /**
     * @param string $archiveId
     * @return void
     */
    public function openVerify(string $archiveId): void
    {
        BackupAccess::authorize(BackupAccess::CREATE, 'verify', ['archive_id' => $archiveId]);
        $this->verifyArchiveId = $archiveId;
        $this->verifySecret = '';
    }

    /**
     * Check an archive again (needs the backup password or recovery key to open it).
     *
     * @return void
     */
    public function runVerify(): void
    {
        $archiveId = (string) $this->verifyArchiveId;
        BackupAccess::authorize(BackupAccess::CREATE, 'verify', ['archive_id' => $archiveId]);
        $secret = $this->verifyWithRecovery ? BackupSecret::recovery($this->verifySecret) : BackupSecret::password($this->verifySecret);
        $this->verifySecret = '';
        try {
            $jobId = (new ArchiveActions)->verify($archiveId, $secret);
        } catch (BackupRefused $e) {
            $this->refused('verify', $e->reason, ['archive_id' => $archiveId]);

            return;
        } catch (WrongBackupSecret $e) {
            $this->refused('verify', 'wrong_secret', ['archive_id' => $archiveId]);

            return;
        } catch (ArchiveRejected $e) {
            $this->refused('verify', 'archive_rejected', ['archive_id' => $archiveId]);

            return;
        } catch (BackupNotSetUp $e) {
            $this->refused('verify', 'not_set_up', ['archive_id' => $archiveId]);

            return;
        }
        $this->verifyArchiveId = null;
        BackupAudit::record('verify', BackupAudit::OK, ['archive_id' => $archiveId, 'job_id' => $jobId]);
        $this->notice('admin.backup.verify.started');
        $this->dispatch('gp247-backup-run');
    }

    /**
     * @param string $archiveId
     * @return void
     */
    public function togglePin(string $archiveId): void
    {
        BackupAccess::authorize(BackupAccess::CREATE, 'pin', ['archive_id' => $archiveId]);
        $row = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_archive')->where('id', $archiveId)->first();
        try {
            (new ArchiveActions)->pin($archiveId, !(bool) ($row->pinned ?? false));
        } catch (BackupRefused $e) {
            $this->refused('pin', $e->reason, ['archive_id' => $archiveId]);

            return;
        }
        BackupAudit::record('pin', BackupAudit::OK, ['archive_id' => $archiveId, 'pinned' => !(bool) ($row->pinned ?? false)]);
    }

    /**
     * Copy a verified archive to the external destination (again) — after a failure, or one made
     * before the destination was set (US-backup-storage-chunked-upload). No re-confirmation: it
     * only sends the encrypted archive to the place the administrator configured.
     *
     * @param string $archiveId
     * @return void
     *
     * @aidlc-unit backup-storage
     * @aidlc-story US-backup-storage-chunked-upload, US-backup-storage-s3-compatible
     */
    public function pushOffsite(string $archiveId): void
    {
        BackupAccess::authorize(BackupAccess::CREATE, 'offsite_push', ['archive_id' => $archiveId]);
        try {
            $jobId = (new OffsiteService)->retry($archiveId);
        } catch (OffsiteRefused $e) {
            BackupAudit::record('offsite_push', BackupAudit::REFUSED, ['archive_id' => $archiveId, 'reason' => $e->reason]);
            $this->notice('admin.backup.offsite.refused.' . $e->reason, 'error');

            return;
        }
        BackupAudit::record('offsite_push', BackupAudit::OK, ['archive_id' => $archiveId, 'job_id' => $jobId]);
        $this->notice('admin.backup.offsite.pushed');
    }

    /**
     * @param string $archiveId
     * @return void
     */
    public function openDelete(string $archiveId): void
    {
        BackupAccess::authorize(BackupAccess::CREATE, 'delete', ['archive_id' => $archiveId]);
        $this->deleteArchiveId = $archiveId;
        $this->deleteConfirm = '';
    }

    /**
     * Delete after the last 6 characters of the archive id were typed back.
     *
     * @return void
     */
    public function runDelete(): void
    {
        $archiveId = (string) $this->deleteArchiveId;
        BackupAccess::authorize(BackupAccess::CREATE, 'delete', ['archive_id' => $archiveId]);
        $this->resetErrorBag('deleteConfirm');
        if ($archiveId === '' || strtoupper(trim($this->deleteConfirm)) !== substr($archiveId, -6)) {
            $this->addError('deleteConfirm', gp247_language_render('admin.backup.delete.confirm_wrong'));

            return;
        }
        try {
            (new ArchiveActions)->delete($archiveId);
        } catch (BackupRefused $e) {
            $this->refused('delete', $e->reason, ['archive_id' => $archiveId]);

            return;
        }
        $this->deleteArchiveId = null;
        $this->deleteConfirm = '';
        BackupAudit::record('delete', BackupAudit::OK, ['archive_id' => $archiveId]);
        $this->notice('admin.backup.delete.done');
    }

    /**
     * Download: re-confirm, then a single-use link for this admin (the browser follows it).
     *
     * @param string $archiveId
     * @return void
     */
    public function requestDownload(string $archiveId): void
    {
        BackupAccess::authorize(BackupAccess::DOWNLOAD, 'download_link', ['archive_id' => $archiveId]);
        if (!$this->requireStepUp('requestDownload', [$archiveId])) {
            return;
        }
        try {
            $url = (new DownloadTokenService)->issue($archiveId, (string) admin()->user()->id);
        } catch (BackupRefused $e) {
            $this->refused('download_link', $e->reason, ['archive_id' => $archiveId]);

            return;
        }
        BackupAudit::record('download_link', BackupAudit::OK, ['archive_id' => $archiveId]);
        // WHY: the URL carries a link token — sent as a one-off redirect, never kept in a public property.
        $this->redirect($url);
    }

    /**
     * Stop the backup in progress (its files are removed).
     *
     * @param string $archiveId
     * @return void
     */
    public function cancelBackup(string $archiveId): void
    {
        BackupAccess::authorize(BackupAccess::CREATE, 'cancel', ['archive_id' => $archiveId]);
        try {
            (new BackupService)->cancel($archiveId);
        } catch (BackupRefused $e) {
            $this->refused('cancel', $e->reason, ['archive_id' => $archiveId]);

            return;
        }
        BackupAudit::record('cancel', BackupAudit::OK, ['archive_id' => $archiveId]);
    }

    /**
     * @return View
     * @throws AuthorizationException
     */
    public function render(): View
    {
        $this->authorizeScreen();
        $setUp = (new KeyService)->isSetUp();
        $registry = new DataGroupRegistry;

        return view('gp247-admin::backup.dashboard', [
            'enabled' => BackupFeature::enabled(),
            'setUp' => $setUp,
            'status' => (new DashboardStatus)->summary(),
            'rpo' => (new ScheduleClock)->rpo(),
            'archives' => $setUp ? $this->archives() : null,
            'groups' => $registry->groups(),
            'otherTables' => count($registry->unassigned()),
            'fileGroups' => array_keys((array) config('gp247-config.backup.file_groups', [])),
            'can' => [
                'create' => BackupAccess::allows(BackupAccess::CREATE),
                'download' => BackupAccess::allows(BackupAccess::DOWNLOAD),
                'restore' => BackupAccess::allows(BackupAccess::RESTORE),
                'settings' => BackupAccess::allows(BackupAccess::SETTINGS),
                'offsite' => (new OffsiteService)->configured(),
            ],
            'staleHours' => DashboardStatus::staleHours(),
            'postIncident' => PostIncidentChecklist::pending(),
            'stepUpNeedsCode' => $this->stepUpOpen && $this->stepUpNeedsCode(),
            'tickUrl' => gp247_route_admin('admin.backup.tick'),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('admin.backup.title'),
        ]);
    }

    /**
     * The tasks left by a post-incident restore are done (Administrator).
     *
     * @return void
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    public function markPostIncidentDone(): void
    {
        $this->authorizeAdministrator('post_incident_done');
        if (PostIncidentChecklist::markDone((string) admin()->user()->username)) {
            BackupAudit::record('post_incident_done', BackupAudit::OK);
            $this->notice('admin.backup.post_incident.marked_done');
        }
    }

    /**
     * @return list<string>
     */
    protected function stepUpActions(): array
    {
        return ['requestDownload'];
    }

    /**
     * @param string        $kind
     * @param callable      $scope Builds the BackupScope (may throw BackupRefused).
     * @param bool          $confirmLarge
     * @return void
     */
    private function create(string $kind, callable $scope, bool $confirmLarge = false): void
    {
        BackupAccess::authorize(BackupAccess::CREATE, 'create', ['kind' => $kind]);
        try {
            $created = (new BackupService)->create($scope(), Archive::REASON_MANUAL, [
                'via' => BackupService::VIA_WEB,
                'created_by' => (string) admin()->user()->username,
                'confirm_large' => $confirmLarge,
            ]);
        } catch (BackupRefused $e) {
            if ($e->reason === BackupRefused::NEEDS_CONFIRMATION) {
                $this->confirmKind = $kind;

                return;
            }
            $this->refused('create', $e->reason, ['kind' => $kind]);

            return;
        } catch (BackupNotSetUp $e) {
            $this->refused('create', 'not_set_up', ['kind' => $kind]);

            return;
        }
        BackupAudit::record('create', BackupAudit::OK, ['kind' => $kind, 'archive_id' => $created['archive_id'], 'job_id' => $created['job_id']]);
        $this->notice('admin.backup.create.started');
        $this->dispatch('gp247-backup-run');
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    private function archives()
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_archive')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * @return void
     * @throws AuthorizationException
     */
    private function authorizeScreen(): void
    {
        foreach ([BackupAccess::CREATE, BackupAccess::DOWNLOAD, BackupAccess::RESTORE] as $right) {
            if (BackupAccess::allows($right)) {
                return;
            }
        }
        BackupAudit::record('screen', BackupAudit::DENIED);

        throw AuthorizationException::fromReason('a backup right is required');
    }

    /**
     * @param string $action
     * @return void
     * @throws AuthorizationException
     */
    private function authorizeAdministrator(string $action): void
    {
        BackupAccess::authorize(BackupAccess::SETTINGS, $action);
    }

    /**
     * @param string $key Recovery key as shown or typed.
     * @return string Upper-case base32 characters only.
     */
    private static function normalizeKey(string $key): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $key));
    }
}
