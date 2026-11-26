{{--
    Dialog around an action on one archive of the list (delete, check again): it opens over the
    page, so the confirmation is never at the bottom of a long list, and it names the archive it
    acts on. Same overlay classes as the "Confirm it is you" box (prebuilt bundle, gp247.md §3a).

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-dashboard

    Used with @component(...)…@endcomponent: the form is the slot.
    Variables: $testid (string, data-testid of the dialog), $title (string), $archive (row|null),
    $groups (key => DataGroup), $error (string|null, shown in the dialog), $stepUpOpen (bool —
    Escape is left to the box on top while it is open).
--}}
<div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="{{ $testid }}"
    x-data x-init="$nextTick(() => $el.querySelector('input:not([type=checkbox])')?.focus())"
    @if (!$stepUpOpen) x-on:keydown.escape.window="$wire.closeArchiveDialog()" @endif>
    <div class="absolute inset-0 bg-gray-900/50" wire:click="closeArchiveDialog"></div>
    <div class="relative w-full max-w-lg rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800" role="dialog" aria-modal="true">
        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-700">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ $title }}</h3>
            <button type="button" wire:click="closeArchiveDialog" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" aria-label="{{ gp247_language_render('admin.close') }}">&times;</button>
        </div>
        <div class="space-y-4 p-5">
            @if ($archive)
                <dl class="grid grid-cols-1 gap-1 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700" data-testid="{{ $testid }}-archive">
                    <div class="text-gray-800 dark:text-gray-100">
                        {{ $archive->created_at }} · {{ gp247_language_render('admin.backup.kind.' . $archive->kind) }}
                        · {{ number_format(((int) $archive->size) / 1048576, 1) }} MB
                        · <span class="font-mono">{{ substr((string) $archive->id, -6) }}</span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ \GP247\Core\Backup\Livewire\GroupText::scopeNames((array) (json_decode((string) $archive->scope, true) ?: []), $groups) ?: ($archive->kind === 'full' ? gp247_language_render('admin.backup.list.scope_all') : '—') }}</div>
                </dl>
            @endif
            @if ($error)
                <p class="text-sm text-red-600" data-testid="{{ $testid }}-error">{{ $error }}</p>
            @endif
            {{ $slot }}
        </div>
    </div>
</div>
