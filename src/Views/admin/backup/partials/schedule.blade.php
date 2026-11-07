{{--
    "Schedule" block of the backup settings (BackupScheduleSettings, Administrator only): mode, cycle,
    hour (cron), what a scheduled backup holds, the cron line to install.

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-schedule
    @aidlc-adr backup-admin_browser-beacon-scheduling

    Variables: $modes, $cycles, $kinds (lists), $setUp (bool), $cronLine (string), $cronCommand (string), $cronMissed (bool).
--}}
@php($inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100')
<div data-testid="backup-schedule">
    <x-gp247::card :title="gp247_language_render('admin.backup.schedule.title')">
        <div class="space-y-4">
            @if ($notice)
                <x-gp247::alert :type="$noticeType" data-testid="backup-schedule-notice">{{ $notice }}</x-gp247::alert>
            @endif
            @if (!$setUp && $mode !== 'off')
                <x-gp247::alert type="warning" data-testid="backup-schedule-not-set-up">{{ gp247_language_render('admin.backup.schedule.not_set_up') }}</x-gp247::alert>
            @endif

            <form wire:submit="save" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <label class="block text-sm text-gray-700 dark:text-gray-200" style="grid-column: 1 / -1">
                    <span class="mb-1 block font-medium">{{ gp247_language_render('admin.backup.schedule.mode') }}</span>
                    <select wire:model.live="mode" class="{{ $inputCls }}" data-testid="backup-schedule-mode">
                        @foreach ($modes as $option)
                            <option value="{{ $option }}">{{ gp247_language_render('admin.backup.schedule.mode.' . $option) }}</option>
                        @endforeach
                    </select>
                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.schedule.mode_help.' . $mode) }}</span>
                    @error('mode') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                @if ($mode !== 'off')
                    <label class="block text-sm text-gray-700 dark:text-gray-200">
                        <span class="mb-1 block font-medium">{{ gp247_language_render('admin.backup.schedule.hours') }}</span>
                        <select wire:model="hours" class="{{ $inputCls }}" data-testid="backup-schedule-hours">
                            @foreach ($cycles as $cycle)
                                <option value="{{ $cycle }}">{{ gp247_language_render('admin.backup.schedule.every', ['hours' => $cycle]) }}</option>
                            @endforeach
                        </select>
                        @error('hours') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm text-gray-700 dark:text-gray-200">
                        <span class="mb-1 block font-medium">{{ gp247_language_render('admin.backup.schedule.kind') }}</span>
                        <select wire:model="kind" class="{{ $inputCls }}" data-testid="backup-schedule-kind">
                            @foreach ($kinds as $option)
                                <option value="{{ $option }}">{{ gp247_language_render('admin.backup.schedule.kind.' . $option) }}</option>
                            @endforeach
                        </select>
                        @error('kind') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endif

                @if ($mode === 'cron')
                    <div>
                        <x-gp247::input type="text" name="at" wire:model="at" placeholder="03:00"
                            :label="gp247_language_render('admin.backup.schedule.at')" :error="$errors->first('at')" data-testid="backup-schedule-at" />
                    </div>
                    <div class="space-y-3 rounded-lg border border-gray-200 p-4 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-200" style="grid-column: 1 / -1" data-testid="backup-schedule-cron-guide">
                        <p>{{ gp247_language_render('admin.backup.schedule.cron_guide') }}</p>
                        <pre class="overflow-x-auto rounded-lg border border-gray-200 p-3 font-mono text-xs dark:border-gray-700">{{ $cronLine }}</pre>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.schedule.cron_task', ['command' => $cronCommand]) }}</p>
                    </div>
                    @if ($cronMissed)
                        <div style="grid-column: 1 / -1">
                            <x-gp247::alert type="warning" data-testid="backup-schedule-cron-missed">{{ gp247_language_render('admin.backup.schedule.cron_missed') }}</x-gp247::alert>
                        </div>
                    @endif
                @endif

                <div style="grid-column: 1 / -1">
                    <x-gp247::button type="submit" data-testid="backup-schedule-save">{{ gp247_language_render('admin.backup.schedule.save') }}</x-gp247::button>
                </div>
            </form>
        </div>
    </x-gp247::card>
</div>
