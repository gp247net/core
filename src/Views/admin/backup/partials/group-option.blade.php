{{--
    One group to tick: checkbox, name and what the group holds in business words. Shared by the backup
    screen (selected groups, data and file groups) and the restore screen (replace data groups).

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-dashboard, US-backup-restore-table-restore

    Variables: $model ('selectiveGroups' | 'selectiveFiles' | 'groups'), $live (bool, wire:model.live),
    $value (key), $label, $help (may be empty), $testid (checkbox data-testid).
--}}
<label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
    @if ($live)
        <x-gp247::checkbox wire:model.live="{{ $model }}" value="{{ $value }}" data-testid="{{ $testid }}" />
    @else
        <x-gp247::checkbox wire:model="{{ $model }}" value="{{ $value }}" data-testid="{{ $testid }}" />
    @endif
    <span>
        <span class="block">{{ $label }}</span>
        @if ($help !== '')
            <span class="block text-xs text-gray-500 dark:text-gray-400" data-testid="backup-group-help-{{ $value }}">{{ $help }}</span>
        @endif
    </span>
</label>
