<?php

namespace GP247\Core\PersonalData\Livewire;

use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\AdminShell\Infrastructure\ConfigForm;
use GP247\Core\PersonalData\Application\Requests\PrivacySettings;

/**
 * "Personal data" tab of the Configuration hub: one policy for the whole site — how long an
 * erasure waits so the customer can change their mind (0–30 days), and after how many days an
 * unhandled request is overdue. Administrators only: the path is in administratorOnly(), and
 * the form checks again because a hub tab is not opened through its own route.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-settings
 * @aidlc-adr personal-data-requests_access-and-settings
 */
class PrivacySettingsForm extends ConfigForm
{
    protected ?string $permission = 'admin_personal_data_settings';

    /**
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function mount(): void
    {
        $this->authorizeAdministrator();
        parent::mount();
    }

    /**
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    public function save(): void
    {
        $this->authorizeAdministrator();
        parent::save();
    }

    /**
     * @return void
     * @throws AuthorizationException When the current admin is not an Administrator.
     */
    private function authorizeAdministrator(): void
    {
        // WHY: View.all reads every screen, but this is one policy for the whole site.
        if (!app(AdminUserContract::class)->isAdministrator()) {
            throw AuthorizationException::fromReason('personal data settings are for administrators only');
        }
    }

    /**
     * @return string
     */
    protected function group(): string
    {
        return PrivacySettings::GROUP;
    }

    /**
     * @return string
     */
    protected function heading(): string
    {
        return gp247_language_render('admin.personal_data.settings.title');
    }

    /**
     * @return list<string>
     */
    protected function keys(): array
    {
        return [PrivacySettings::WAIT_DAYS, PrivacySettings::OVERDUE_DAYS];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldTypes(): array
    {
        return [PrivacySettings::WAIT_DAYS => 'number', PrivacySettings::OVERDUE_DAYS => 'number'];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldHints(): array
    {
        return [
            PrivacySettings::WAIT_DAYS => (string) gp247_language_render('admin.personal_data.settings.erase_wait_days_hint'),
            PrivacySettings::OVERDUE_DAYS => (string) gp247_language_render('admin.personal_data.settings.overdue_days_hint'),
        ];
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @return mixed Days kept within their limits.
     */
    protected function normalizeValue(string $key, $value)
    {
        return match ($key) {
            PrivacySettings::WAIT_DAYS => (string) PrivacySettings::clampWait($value),
            PrivacySettings::OVERDUE_DAYS => (string) PrivacySettings::clampOverdue($value),
            default => $value,
        };
    }
}
