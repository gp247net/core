{{--
    Progress of the backup job in progress (backup, check, restore, import, copy off-site): what it
    does and on which backup, a progress bar, the steps of that kind of job (done / under way /
    to come), the count of the step under way, how long it has run, and whether it is moving.
    Only class combinations of the prebuilt admin bundle (gp247.md §3a); the bar width is a style.

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-dashboard

    Variables: $job (DashboardStatus::activeJob()), $testid (string), $cancel (string|null — Livewire
    call of the Stop button, already with its argument), $cancelTestid (string|null).
--}}
@php($elapsed = sprintf($job['elapsed'] >= 3600 ? '%d:%02d:%02d' : '%2$d:%3$02d', intdiv($job['elapsed'], 3600), intdiv($job['elapsed'] % 3600, 60), $job['elapsed'] % 60))
<div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700" data-testid="{{ $testid }}" aria-live="polite">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">
            {{ gp247_language_render('admin.backup.job.' . $job['type']) }}
            @if ($job['archive_id']) · <span class="font-mono">{{ substr($job['archive_id'], -6) }}</span>@endif
        </p>
        <p class="text-sm text-gray-600 dark:text-gray-300" data-testid="{{ $testid }}-percent">{{ $job['percent'] }}%</p>
    </div>

    <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
        role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $job['percent'] }}"
        aria-label="{{ gp247_language_render('admin.backup.job.' . $job['type']) }}">
        <div class="h-2 rounded-full transition-all {{ $job['stalled'] ? 'bg-amber-500' : 'bg-blue-600' }}" style="width: {{ max(2, $job['percent']) }}%"></div>
    </div>

    <ol class="mt-3 flex flex-wrap gap-3 text-xs" data-testid="{{ $testid }}-steps">
        @foreach ($job['steps'] as $step)
            <li @class([
                    'text-green-600 dark:text-green-300' => $step['state'] === 'done',
                    'font-semibold text-blue-600 dark:text-blue-400' => $step['state'] === 'current',
                    'text-gray-400 dark:text-gray-500' => $step['state'] === 'todo',
                ]) data-state="{{ $step['state'] }}">
                {{ $step['state'] === 'done' ? '✓' : ($step['state'] === 'current' ? '●' : '○') }}
                {{ gp247_language_render('admin.backup.step.' . $step['key']) }}
            </li>
        @endforeach
    </ol>

    @php($count = $job['total'] === null ? null : ($job['unit'] === 'bytes'
        ? number_format($job['done'] / 1048576, 1) . ' / ' . number_format($job['total'] / 1048576, 1) . ' MB'
        : gp247_language_render('admin.backup.progress.count_' . $job['unit'], ['done' => $job['done'], 'total' => $job['total']])))
    <p class="mt-3 text-sm text-gray-700 dark:text-gray-200" data-testid="{{ $testid }}-detail">
        {{ implode(' · ', array_filter([
            gp247_language_render('admin.backup.step.' . $job['current']),
            $count,
            gp247_language_render('admin.backup.progress.elapsed', ['time' => $elapsed]),
        ])) }}
    </p>
    @if ($job['current'] === 'swap')
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.progress.swap_help') }}</p>
    @endif

    @if ($job['stalled'])
        <p class="mt-3 text-sm text-amber-700" data-testid="{{ $testid }}-stalled">
            {{ gp247_language_render('admin.backup.progress.stalled', ['minutes' => max(1, intdiv($job['idle'], 60))]) }}
        </p>
    @elseif ($job['status'] === 'pending')
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.progress.pending') }}</p>
    @endif

    @if ($cancel)
        <x-gp247::button variant="danger" size="sm" class="mt-3" wire:click="{{ $cancel }}" data-testid="{{ $cancelTestid }}">{{ gp247_language_render('admin.backup.job.cancel') }}</x-gp247::button>
    @endif
</div>
