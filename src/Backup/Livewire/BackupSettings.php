<?php

namespace GP247\Core\Backup\Livewire;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\Backup\Application\BackupAccess;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\BackupFeature;
use GP247\Core\Backup\Application\KeyService;
use GP247\Core\Backup\Application\RetentionService;
use GP247\Core\Backup\Application\SecretAttemptLimiter;
use GP247\Core\Backup\Domain\BackupKeyError;
use GP247\Core\Backup\Domain\BackupRefused;
use GP247\Core\Backup\Domain\BackupSecret;
use GP247\Core\Backup\Domain\WrongBackupSecret;
use GP247\Core\Backup\Infrastructure\Install\BackupInstaller;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Livewire\Concerns\ConfirmsStepUp;
use GP247\Core\Backup\Livewire\Concerns\RendersNotices;
use GP247\Core\Models\AdminConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Backup settings (Administrator only — the path is in PermissionMiddleware::administratorOnly()
 * and every action checks again): the on/off switch (off keeps every archive and hides the menu),
 * how many archives to keep, an optional storage folder outside the web root, and — after a
 * re-confirmation — a new backup password or a new recovery key (each shown once).
 * External destinations come in BK-6, the schedule in BK-7.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-engine-key-setup, US-backup-admin-permissions, US-backup-admin-step-up-auth
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
class BackupSettings extends Component
{
    use ConfirmsStepUp;
    use RendersNotices;

    public bool $enabled = true;
    public int $keepCount = RetentionService::DEFAULT_KEEP;
    public int $keepPreChange = RetentionService::DEFAULT_KEEP_PRE_CHANGE;
    public string $vaultPath = '';

    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPasswordConfirm = '';
    public bool $useRecoveryForChange = false;
    /** Recovery key shown once after a change; cleared when the box is closed. */
    public ?string $shownRecoverySecret = null;

    /**
     * @return void
     * @throws AuthorizationException
     */
    public function mount(): void
    {
        $this->authorizeAdministrator('settings');
        $this->enabled = BackupFeature::enabled();
        $settings = RetentionService::settings();
        $this->keepCount = $settings['keep'];
        $this->keepPreChange = $settings['keep_pre_change'];
        $this->vaultPath = (string) AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', LocalVault::CONFIG_PATH)->value('value');
    }

    /**
     * @return void
     */
    public function saveGeneral(): void
    {
        $this->authorizeAdministrator('settings');
        $this->validate([
            'keepCount' => ['required', 'integer', 'min:1', 'max:100'],
            'keepPreChange' => ['required', 'integer', 'min:1', 'max:20'],
            'vaultPath' => ['nullable', 'string', 'max:500'],
        ]);
        // Fewer archives kept, another folder, the feature off: each can make backups disappear,
        // so a taken-over session is not enough (mod 20261110T164834, D6).
        if (!$this->requireStepUp('saveGeneral')) {
            return;
        }
        try {
            LocalVault::setCustomPath($this->vaultPath === '' ? null : $this->vaultPath);
        } catch (BackupRefused $e) {
            $this->addError('vaultPath', gp247_language_render('admin.backup.settings.vault_unsafe'));
            BackupAudit::record('settings', BackupAudit::REFUSED, ['reason' => $e->reason]);

            return;
        }
        AdminConfig::setConfigValue('backup', RetentionService::CONFIG_KEEP, GP247_STORE_ID_GLOBAL, (string) $this->keepCount, 0);
        AdminConfig::setConfigValue('backup', RetentionService::CONFIG_KEEP_PRE_CHANGE, GP247_STORE_ID_GLOBAL, (string) $this->keepPreChange, 0);
        BackupFeature::setEnabled($this->enabled);
        BackupInstaller::setMenuVisible(DB::connection(GP247_DB_CONNECTION), $this->enabled);
        BackupAudit::record('settings', BackupAudit::OK, ['status' => $this->enabled ? 'on' : 'off']);
        $this->notice('admin.backup.settings.saved');
    }

    /**
     * New backup password (current password or recovery key + re-confirmation); a new recovery
     * key comes with it.
     *
     * @return void
     */
    public function changePassword(): void
    {
        $this->authorizeAdministrator('key_change');
        $this->validate([
            'currentPassword' => ['required', 'string', 'max:1024'],
            'newPassword' => ['required', 'string', 'min:' . KeyService::MIN_PASSWORD_LENGTH, 'max:1024'],
            'newPasswordConfirm' => ['required', 'same:newPassword'],
        ]);
        if (!$this->requireStepUp('changePassword')) {
            return;
        }
        if ($this->secretThrottled('key_change')) {
            return;
        }
        $current = $this->useRecoveryForChange ? BackupSecret::recovery($this->currentPassword) : BackupSecret::password($this->currentPassword);
        try {
            $recovery = (new KeyService)->changePassword($current, $this->newPassword);
            SecretAttemptLimiter::clear((string) admin()->user()->id);
        } catch (WrongBackupSecret $e) {
            SecretAttemptLimiter::failed((string) admin()->user()->id);
            $this->addError('currentPassword', gp247_language_render('admin.backup.refused.wrong_secret'));
            BackupAudit::record('key_change', BackupAudit::REFUSED, ['reason' => 'wrong_secret']);

            return;
        } catch (BackupKeyError $e) {
            $this->refused('key_change', 'key_error');

            return;
        } finally {
            $this->currentPassword = '';
        }
        $this->newPassword = '';
        $this->newPasswordConfirm = '';
        $this->shownRecoverySecret = $recovery->display();
        BackupAudit::record('key_change', BackupAudit::OK);
    }

    /**
     * New recovery key (the old one stops working); needs the backup password + re-confirmation.
     *
     * @return void
     */
    public function newRecoveryKey(): void
    {
        $this->authorizeAdministrator('recovery_key');
        $this->validate(['currentPassword' => ['required', 'string', 'max:1024']]);
        if (!$this->requireStepUp('newRecoveryKey')) {
            return;
        }
        if ($this->secretThrottled('recovery_key')) {
            return;
        }
        try {
            $recovery = (new KeyService)->regenerateRecoveryKey($this->currentPassword);
            SecretAttemptLimiter::clear((string) admin()->user()->id);
        } catch (WrongBackupSecret $e) {
            SecretAttemptLimiter::failed((string) admin()->user()->id);
            $this->addError('currentPassword', gp247_language_render('admin.backup.refused.wrong_secret'));
            BackupAudit::record('recovery_key', BackupAudit::REFUSED, ['reason' => 'wrong_secret']);

            return;
        } finally {
            $this->currentPassword = '';
        }
        $this->shownRecoverySecret = $recovery->display();
        BackupAudit::record('recovery_key', BackupAudit::OK);
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
        $this->currentPassword = '';
        $this->refused($action, SecretAttemptLimiter::REASON);

        return true;
    }

    /**
     * @return void
     */
    public function closeRecoveryKey(): void
    {
        $this->shownRecoverySecret = null;
    }

    /**
     * @return View
     * @throws AuthorizationException
     */
    public function render(): View
    {
        $this->authorizeAdministrator('settings');

        return view('gp247-admin::backup.settings', [
            'setUp' => (new KeyService)->isSetUp(),
            'stepUpNeedsCode' => $this->stepUpOpen && $this->stepUpNeedsCode(),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('admin.backup.settings.title'),
        ]);
    }

    /**
     * @return list<string>
     */
    protected function stepUpActions(): array
    {
        return ['changePassword', 'newRecoveryKey', 'saveGeneral'];
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
