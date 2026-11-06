<?php

namespace GP247\Core\Backup\Livewire;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\Backup\Application\BackupAccess;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\DestinationRegistry;
use GP247\Core\Backup\Application\OffsiteService;
use GP247\Core\Backup\Domain\ConnectionReport;
use GP247\Core\Backup\Domain\DestinationType;
use GP247\Core\Backup\Domain\OffsiteRefused;
use GP247\Core\Backup\Livewire\Concerns\ConfirmsStepUp;
use GP247\Core\Backup\Livewire\Concerns\RendersNotices;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "External destination" block of the backup settings (Administrator only — the page path is in
 * PermissionMiddleware::administratorOnly() and every action checks again). Choose a type, fill
 * its fields; secrets are write-only (empty on every load, blank = keep, never sent back to the
 * browser); test the connection; save; forget the destination. Testing, saving, forgetting and
 * accepting a new host key all need a fresh re-confirmation.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp, US-backup-storage-credential-secret, US-backup-storage-immutable-guide
 * @aidlc-adr backup-storage_optional-adapters, backup-admin_step-up-and-signed-download
 */
class BackupDestinationSettings extends Component
{
    use ConfirmsStepUp;
    use RendersNotices;

    public string $type = '';
    /** @var array<string, mixed> Plain fields of the chosen type. */
    public array $fields = [];
    /** @var array<string, string> Typed secrets (write-only; the name keeps them out of the operation log). */
    public array $secretInputs = [];
    /** @var array<string, mixed>|null Last test of this screen (no secret in it). */
    #[Locked]
    public ?array $report = null;
    /** Host key fingerprint the last test saw (SFTP), offered for pinning — set by the server only. */
    #[Locked]
    public ?string $seenFingerprint = null;

    /**
     * @return void
     * @throws AuthorizationException
     */
    public function mount(): void
    {
        $this->authorizeAdministrator('settings');
        $this->loadStored();
    }

    /**
     * @return void
     */
    public function updatedType(): void
    {
        $definition = $this->definition();
        $current = (new OffsiteService)->config()->current();
        $this->fields = $definition === null ? [] : $definition->cleanFields($current !== null && $current['type'] === $this->type ? $current['fields'] : []);
        $this->secretInputs = [];
        $this->report = null;
        $this->seenFingerprint = null;
        $this->resetErrorBag();
    }

    /**
     * Test the configuration as typed (blank secrets = stored ones while the place is unchanged).
     *
     * @return void
     */
    public function testConnection(): void
    {
        $this->authorizeAdministrator('destination_test');
        $this->resetErrorBag();
        if (!$this->stepUpWithoutSecrets('testConnection')) {
            return;
        }
        try {
            $report = (new OffsiteService)->test($this->type, $this->fields, $this->secretInputs);
        } catch (OffsiteRefused $e) {
            $this->refusedConfig('destination_test', $e);

            return;
        } finally {
            // WHY: a typed secret must not travel back in the component state.
            $this->secretInputs = [];
        }
        $this->report = $report->toArray();
        $this->seenFingerprint = $report->fingerprint;
        BackupAudit::record('destination_test', $report->ok() ? BackupAudit::OK : BackupAudit::FAILED, ['kind' => $this->type, 'reason' => (string) $report->error]);
    }

    /**
     * Store the destination, then test it as stored (the rights found are kept for the copy job).
     *
     * @return void
     */
    public function save(): void
    {
        $this->authorizeAdministrator('destination_save');
        $this->resetErrorBag();
        if (!$this->stepUpWithoutSecrets('save')) {
            return;
        }
        $offsite = new OffsiteService;
        $before = $offsite->config()->current();
        try {
            $offsite->config()->save($this->type, $this->fields, $this->secretInputs);
        } catch (OffsiteRefused $e) {
            $this->refusedConfig('destination_save', $e);

            return;
        } finally {
            $this->secretInputs = [];
        }
        $report = $this->testStored($offsite);
        BackupAudit::record('destination_save', BackupAudit::OK, ['kind' => $this->type, 'reason' => (string) $report?->error]);
        $seen = $this->seenFingerprint;
        $this->loadStored();
        $this->seenFingerprint = $seen;
        $definition = $this->definition();
        $moved = $before !== null && ($before['type'] !== $this->type || $definition === null || !$definition->sameIdentity($before['fields'], $this->fields));
        $this->notice($moved ? 'admin.backup.offsite.moved' : 'admin.backup.offsite.saved', $moved ? 'warning' : 'success');
    }

    /**
     * Re-confirmation before an action that may carry typed secrets. When it is needed, the
     * typed secrets are dropped at once: the component state goes back to the browser with the
     * re-confirmation dialog and must never hold a password or key (they are typed again after).
     *
     * @param string $action
     * @return bool The action may run now.
     */
    private function stepUpWithoutSecrets(string $action): bool
    {
        if ($this->requireStepUp($action)) {
            return true;
        }
        if (array_filter($this->secretInputs, fn ($v) => (string) $v !== '') !== []) {
            $this->secretInputs = [];
            $this->notice('admin.backup.offsite.secret_again', 'warning');
        }

        return false;
    }

    /**
     * Pin the host key the last test saw (after the server's key changed on purpose).
     *
     * @return void
     */
    public function acceptHostKey(): void
    {
        $this->authorizeAdministrator('host_key_accept');
        if ($this->seenFingerprint === null) {
            return;
        }
        if (!$this->requireStepUp('acceptHostKey')) {
            return;
        }
        $offsite = new OffsiteService;
        $current = $offsite->config()->current();
        if ($current === null) {
            return;
        }
        // WHY: the fingerprint shown may come from a test of a server typed but not saved; pin
        // only the key the SAVED server presents now (checked before signing in) and only when
        // it is the one the administrator saw.
        try {
            $check = $offsite->test($current['type'], $current['fields'], []);
        } catch (OffsiteRefused $e) {
            $this->refusedConfig('host_key_accept', $e);

            return;
        }
        if ($check->fingerprint === null || !hash_equals($check->fingerprint, $this->seenFingerprint)) {
            BackupAudit::record('host_key_accept', BackupAudit::REFUSED, ['kind' => $this->type, 'reason' => 'host_key_mismatch']);
            $this->report = $check->toArray();
            $this->seenFingerprint = $check->fingerprint;
            $this->notice('admin.backup.offsite.host_key_mismatch', 'error');

            return;
        }
        $offsite->config()->acceptFingerprint($this->seenFingerprint);
        BackupAudit::record('host_key_accept', BackupAudit::OK, ['kind' => $this->type]);
        $this->testStored($offsite);
        $this->loadStored();
        $this->notice('admin.backup.offsite.host_key_accepted');
    }

    /**
     * Forget the destination (nothing is deleted there; waiting copies are cancelled).
     *
     * @return void
     */
    public function remove(): void
    {
        $this->authorizeAdministrator('destination_remove');
        if (!$this->requireStepUp('remove')) {
            return;
        }
        (new OffsiteService)->remove();
        BackupAudit::record('destination_remove', BackupAudit::OK);
        $this->reset(['type', 'fields', 'secretInputs', 'report', 'seenFingerprint']);
        $this->notice('admin.backup.offsite.removed');
    }

    /**
     * @return View
     * @throws AuthorizationException
     */
    public function render(): View
    {
        $this->authorizeAdministrator('settings');
        $offsite = new OffsiteService;
        $current = $offsite->config()->current();
        $stored = $offsite->config()->report();

        return view('gp247-admin::backup.partials.destination', [
            'types' => (new DestinationRegistry)->types(),
            'definition' => $this->definition(),
            'current' => $current,
            'storedSecrets' => $current !== null && $current['type'] === $this->type ? $offsite->config()->secretNames() : [],
            'shown' => $this->report !== null ? ConnectionReport::fromArray($this->report) : $stored,
            'retention' => $offsite->config()->retentionOutcome(),
            'stepUpNeedsCode' => $this->stepUpOpen && $this->stepUpNeedsCode(),
        ]);
    }

    /**
     * @return list<string>
     */
    protected function stepUpActions(): array
    {
        return ['testConnection', 'save', 'acceptHostKey', 'remove'];
    }

    /**
     * @param OffsiteService $offsite
     * @return ConnectionReport|null
     */
    private function testStored(OffsiteService $offsite): ?ConnectionReport
    {
        $current = $offsite->config()->current();
        if ($current === null) {
            return null;
        }
        try {
            $report = $offsite->test($current['type'], $current['fields'], []);
        } catch (OffsiteRefused $e) {
            return null;
        }
        if ($current['fingerprint'] === null && $report->fingerprint !== null && $report->error === null) {
            // First connection to this server: its key is recorded and shown, and every later
            // connection must present the same key (US-backup-storage-sftp).
            $offsite->config()->acceptFingerprint($report->fingerprint);
        }
        $offsite->config()->saveReport($report);
        $this->report = $report->toArray();
        $this->seenFingerprint = $report->fingerprint;

        return $report;
    }

    /**
     * @return void
     */
    private function loadStored(): void
    {
        $current = (new OffsiteService)->config()->current();
        $this->type = $current['type'] ?? '';
        $definition = $this->definition();
        $this->fields = $definition === null ? [] : $definition->cleanFields($current['fields'] ?? []);
        $this->secretInputs = [];
        $this->seenFingerprint = null;
    }

    /**
     * @return DestinationType|null
     */
    private function definition(): ?DestinationType
    {
        return $this->type === '' ? null : (new DestinationRegistry)->type($this->type);
    }

    /**
     * @param string         $action
     * @param OffsiteRefused $e
     * @return void
     */
    private function refusedConfig(string $action, OffsiteRefused $e): void
    {
        BackupAudit::record($action, BackupAudit::REFUSED, ['kind' => $this->type, 'reason' => $e->reason]);
        if ($e->reason !== OffsiteRefused::INVALID) {
            $this->notice('admin.backup.offsite.refused.' . $e->reason, 'error');

            return;
        }
        foreach ($e->problems as $problem) {
            [$field, $code] = array_pad(explode(':', $problem, 2), 2, 'invalid');
            $bag = array_key_exists($field, $this->fields) ? 'fields.' . $field : 'secretInputs.' . $field;
            $this->addError($bag, gp247_language_render('admin.backup.offsite.problem.' . preg_replace('/[^a-z_]/', '', $code)));
        }
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
}
