<?php

namespace GP247\Core\Backup\Livewire;

use GP247\Core\Backup\Application\BackupAccess;
use GP247\Core\Backup\Application\BackupFeature;
use GP247\Core\Backup\Application\ScheduleClock;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Header widget of the "when an admin visits" schedule (ADR backup-admin_browser-beacon-scheduling):
 * on admin pages only, for an admin holding the create right, and only while a scheduled backup
 * is due or a backup / check / off-site copy is in progress, it posts background ticks to
 * `<admin>/backup/tick` after the page has shown — one short slice each, repeated while the server
 * says "more", stopped when the tab is hidden or on any error. Otherwise it renders nothing and
 * sends nothing. Storefront requests never load it.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-schedule
 * @aidlc-adr backup-admin_browser-beacon-scheduling
 */
class BackupBeaconWidget extends Component
{
    /**
     * @return View
     */
    public function render(): View
    {
        return view('gp247-admin::backup.partials.beacon', [
            'active' => $this->active(),
            'tickUrl' => gp247_route_admin('admin.backup.tick'),
        ]);
    }

    /**
     * @return bool Ticks should run on this page.
     */
    private function active(): bool
    {
        try {
            if (!BackupFeature::enabled() || !BackupAccess::allows(BackupAccess::CREATE)) {
                return false;
            }
            $jobs = new JobRepository;
            foreach ([Archive::JOB_BACKUP, Archive::JOB_VERIFY, Archive::JOB_UPLOAD] as $type) {
                if ($jobs->activeOfType($type) !== []) {
                    return true;
                }
            }

            return (new ScheduleClock($jobs))->due();
        } catch (\Throwable $e) {
            // The header of every admin page: never break it because of the backup area.
            return false;
        }
    }
}
