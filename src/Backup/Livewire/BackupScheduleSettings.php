<?php

namespace GP247\Core\Backup\Livewire;

use GP247\Core\Backup\Application\BackupAccess;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\KeyService;
use GP247\Core\Backup\Application\ScheduleClock;
use GP247\Core\Backup\Application\ScheduleSettings;
use GP247\Core\Backup\Infrastructure\ScheduleRegistrar;
use GP247\Core\Backup\Livewire\Concerns\RendersNotices;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "Schedule" block of the backup settings (Administrator only): mode (off · when an admin visits ·
 * cron), cycle, hour (cron), what a scheduled backup holds; the cron line to install and a warning
 * when cron mode never ran. No re-confirmation: nothing leaves the site.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-schedule
 * @aidlc-adr backup-admin_browser-beacon-scheduling
 */
class BackupScheduleSettings extends Component
{
    use RendersNotices;

    public string $mode = ScheduleSettings::MODE_VISIT;
    public int $hours = 24;
    public string $at = '03:00';
    public string $kind = ScheduleSettings::KIND_FULL;

    /**
     * @return void
     */
    public function mount(): void
    {
        BackupAccess::authorize(BackupAccess::SETTINGS, 'settings');
        $settings = ScheduleSettings::read();
        $this->mode = $settings['mode'];
        $this->hours = $settings['hours'];
        $this->at = $settings['at'];
        $this->kind = $settings['kind'];
    }

    /**
     * @return void
     */
    public function save(): void
    {
        BackupAccess::authorize(BackupAccess::SETTINGS, 'schedule');
        $this->resetErrorBag();
        $invalid = ScheduleSettings::save($this->mode, $this->hours, $this->at, $this->kind);
        if ($invalid !== []) {
            foreach ($invalid as $field) {
                $this->addError($field, gp247_language_render('admin.backup.schedule.invalid'));
            }
            BackupAudit::record('schedule', BackupAudit::REFUSED, ['reason' => implode(',', $invalid)]);

            return;
        }
        ScheduleClock::forget();
        BackupAudit::record('schedule', BackupAudit::OK, ['status' => $this->mode, 'kind' => $this->kind]);
        $this->notice('admin.backup.schedule.saved');
    }

    /**
     * @return View
     */
    public function render(): View
    {
        BackupAccess::authorize(BackupAccess::SETTINGS, 'settings');

        return view('gp247-admin::backup.partials.schedule', [
            'modes' => ScheduleSettings::MODES,
            'cycles' => ScheduleSettings::HOURS_ALLOWED,
            'kinds' => ScheduleSettings::KINDS,
            'setUp' => app(KeyService::class)->isSetUp(),
            'cronLine' => '* * * * * cd ' . base_path() . ' && ' . PHP_BINARY . ' artisan schedule:run >> /dev/null 2>&1',
            'cronCommand' => ScheduleRegistrar::COMMAND,
            'cronMissed' => (new ScheduleClock)->cronMissed(),
        ]);
    }
}
