{{--
    One section of the backup settings, shown in the right-hand pane while it is the one chosen in
    the menu on the left (Alpine `tab` of the settings page). The form stays in the page while
    another section is shown (only hidden), so nothing typed is lost and the server checks are
    unchanged. Prebuilt bundle classes only (gp247.md §3a).

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-dashboard

    Used with @component(...)…@endcomponent: the form is the slot.
    Variables: $key (string — the menu entry), $testid (string), $title, $description (string|null),
    $notice (string|null), $noticeType (string), $noticeTestid (string|null), $note (string|null — a
    warning always shown under the title).
--}}
<section data-testid="{{ $testid }}" x-show="tab === @js($key)" x-cloak>
    <div class="border-b border-gray-200 pb-4 dark:border-gray-700">
        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ $title }}</h3>
        @if ($description)
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
        @if ($note)
            <p class="mt-2 text-xs text-amber-700">{{ $note }}</p>
        @endif
    </div>
    @if ($notice)
        <div class="pt-4">
            <x-gp247::alert :type="$noticeType" data-testid="{{ $noticeTestid ?? $testid . '-notice' }}">{{ $notice }}</x-gp247::alert>
        </div>
    @endif
    <div class="pt-4">
        {{ $slot }}
    </div>
</section>
