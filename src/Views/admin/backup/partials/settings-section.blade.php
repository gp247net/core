{{--
    One section of the backup settings, folded to a line: its title, what is set now (the summary)
    and an Edit button that unfolds the form in place. The whole page reads in one screen; a
    section with an input error, a message or a key to note opens by itself. The form stays in the
    page while folded (only hidden), so nothing typed is lost and the server checks are unchanged.
    Same frame classes as <x-gp247::card> (prebuilt bundle, gp247.md §3a).

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-dashboard

    Used with @component(...)…@endcomponent: the form is the slot.
    Variables: $key (string — closes on the browser event gp247-backup-section-done with this
    section), $title, $summary (string), $tone ('ok'|'warning'|'muted'), $open (bool, open on load),
    $testid (string), $notice (string|null), $noticeType (string), $noticeTestid (string|null),
    $note (string|null — always shown).
--}}
<div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800" data-testid="{{ $testid }}"
    x-data="{ open: @js((bool) $open) }"
    x-on:gp247-backup-section-done.window="if (($event.detail.section ?? $event.detail[0]?.section) === @js($key)) open = false">
    <div class="flex items-start justify-between gap-3 px-5 py-4">
        <div class="min-w-0 flex-1">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{!! $title !!}</h3>
            <p @class([
                    'mt-0.5 text-sm',
                    'text-gray-600 dark:text-gray-300' => $tone === 'ok',
                    'text-amber-700' => $tone === 'warning',
                    'text-gray-500 dark:text-gray-400' => $tone === 'muted',
                ]) data-testid="{{ $testid }}-summary">{{ $summary }}</p>
        </div>
        <x-gp247::button type="button" variant="secondary" size="sm" class="shrink-0" x-on:click="open = !open" x-bind:aria-expanded="open ? 'true' : 'false'" data-testid="{{ $testid }}-toggle">
            <span x-show="!open">{{ gp247_language_render('admin.backup.settings.edit') }}</span>
            <span x-show="open" x-cloak>{{ gp247_language_render('admin.backup.settings.fold') }}</span>
        </x-gp247::button>
    </div>
    @if ($note)
        <p class="px-5 pb-4 text-xs text-amber-700">{{ $note }}</p>
    @endif
    @if ($notice)
        <div class="px-5 pb-4">
            <x-gp247::alert :type="$noticeType" data-testid="{{ $noticeTestid ?? $testid . '-notice' }}">{{ $notice }}</x-gp247::alert>
        </div>
    @endif
    <div class="border-t border-gray-200 p-5 dark:border-gray-700" x-show="open" x-cloak>
        {{ $slot }}
    </div>
</div>
