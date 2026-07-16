{{--
    GP247 modal dialog (ADR-005).

    Opened/closed via browser events targeted by `name`:
      window.dispatchEvent(new CustomEvent('open-modal',  { detail: 'confirm' }))
      window.dispatchEvent(new CustomEvent('close-modal', { detail: 'confirm' }))
    or from Livewire: $this->dispatch('open-modal', 'confirm'). Closes on Escape
    and backdrop click. Alpine ships with Livewire 4.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002
    @aidlc-adr ADR-005

    @props array
      - name (string): unique id this modal responds to.
      - title (string|null): header text.
    @slots default (body), footer
--}}
@props([
    'name' => 'modal',
    'title' => null,
])

<div
    x-data="{ open: false }"
    x-on:open-modal.window="if ($event.detail === '{{ $name }}') open = true"
    x-on:close-modal.window="if ($event.detail === '{{ $name }}') open = false"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
>
    <div class="absolute inset-0 bg-gray-900/50" x-on:click="open = false"></div>

    <div x-show="open" x-transition
        class="relative w-full max-w-lg rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-700">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ $title }}</h3>
            <button type="button" x-on:click="open = false"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" aria-label="{{ gp247_language_render('admin.close') }}">&times;</button>
        </div>

        <div class="p-5">{{ $slot }}</div>

        @isset($footer)
            <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-700">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
