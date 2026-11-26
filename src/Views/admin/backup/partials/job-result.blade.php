{{--
    How the last backup job ended (shown for a while after it, while no job runs): done, failed or
    stopped, on which backup, its size and how long it took — the end of what the progress panel
    showed, in place, instead of a line the person may miss.

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-dashboard

    Variables: $result (DashboardStatus::recentResult()), $testid (string), $dismiss (string — Livewire call).
--}}
@php($ok = $result['status'] === 'done' && ($result['type'] !== 'verify' || $result['archive_status'] === 'ok'))
@php($reasonKey = 'admin.backup.refused.' . (string) $result['error'])
@php($reasonText = $result['error'] !== null && gp247_language_render($reasonKey) !== $reasonKey ? gp247_language_render($reasonKey) : (string) $result['error'])
@php($took = sprintf($result['took'] >= 3600 ? '%d:%02d:%02d' : '%2$d:%3$02d', intdiv($result['took'], 3600), intdiv($result['took'] % 3600, 60), $result['took'] % 60))
<x-gp247::alert :type="$result['status'] === 'cancelled' ? 'warning' : ($ok ? 'success' : 'error')" data-testid="{{ $testid }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <span data-result="{{ $ok ? 'ok' : $result['status'] }}">
            @if ($result['status'] === 'cancelled')
                {{ gp247_language_render('admin.backup.result.cancelled') }}
            @elseif ($ok)
                {{ gp247_language_render('admin.backup.result.' . $result['type']) }}
            @elseif ($result['type'] === 'verify' && $result['status'] === 'done')
                {{ gp247_language_render('admin.backup.result.verify_broken') }}
            @else
                {{ gp247_language_render('admin.backup.result.failed', ['reason' => $reasonText !== '' ? $reasonText : '—']) }}
            @endif
            @if ($result['archive_id']) · <span class="font-mono">{{ substr($result['archive_id'], -6) }}</span>@endif
            @if ($ok && $result['size']) · {{ number_format($result['size'] / 1048576, 1) }} MB @endif
            · {{ gp247_language_render('admin.backup.result.took', ['time' => $took]) }}
        </span>
        <button type="button" wire:click="{{ $dismiss }}" class="text-sm underline" data-testid="{{ $testid }}-dismiss">{{ gp247_language_render('admin.close') }}</button>
    </div>
</x-gp247::alert>
