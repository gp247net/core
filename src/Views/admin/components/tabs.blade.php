{{--
    Client-side tab group (ADR-005), mirroring the legacy AdminLTE pills: all panes
    are rendered up-front and toggled via an Alpine `tab` flag (no server round-trip,
    so nested Livewire children keep their state). Panes live in the default slot and
    show themselves with x-show="tab === '<key>'".

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-005, US-UI-008
    @aidlc-adr ADR-005

    @props
      - tabs (array<string,string>): key => label map for the nav.
      - default (string|null): initially active tab key (defaults to the first).
      - errors (array<int,string>): tab keys that contain validation errors; rendered in red with a dot.
    @slot default: the panes, each wrapped in <div x-show="tab === 'key'" x-cloak>.
--}}
@props([
    'tabs' => [],
    'default' => null,
    'errors' => [],
])

@php
    $default = $default ?? array_key_first($tabs);
    $errorKeys = array_values(array_unique((array) $errors));
@endphp

<div x-data="{ tab: @js($default) }">
    <div class="mb-4 border-b border-gray-200 dark:border-gray-700">
        <nav class="-mb-px flex flex-wrap gap-1" aria-label="Tabs">
            @foreach ($tabs as $key => $label)
                @php($hasError = in_array((string) $key, $errorKeys, true))
                <button type="button" x-on:click="tab = @js($key)"
                    @class([
                        'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors inline-flex items-center gap-1.5',
                    ])
                    :class="tab === @js($key)
                        ? @js($hasError
                            ? 'border-red-500 text-red-600 dark:border-red-400 dark:text-red-400'
                            : 'border-blue-500 text-blue-600 dark:border-blue-400 dark:text-blue-400')
                        : @js($hasError
                            ? 'border-transparent text-red-600 hover:border-red-300 dark:text-red-400'
                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200')">
                    {{ $label }}
                    @if ($hasError)
                        {{-- WHY: visible cue when a hidden tab contains validation errors. --}}
                        <span class="inline-block h-2 w-2 rounded-full bg-red-500" aria-hidden="true"></span>
                        <span class="sr-only">{{ gp247_language_render('admin.error') ?: 'error' }}</span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    <div>{{ $slot }}</div>
</div>
