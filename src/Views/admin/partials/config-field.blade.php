{{--
    Single live-editing config input bound to values.<key> (ADR-005): a checkbox
    (bool), a numeric input (number) or a text input. Persists via the component's
    updatedValues() hook (checkbox = live, number/text = on blur).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-005
    @aidlc-adr ADR-005

    Variables: $key (string), $type (bool|number|select|text), $options (array, for "select").
--}}
@if ($type === 'toggle')
    {{-- On/off switch (same boolean binding as a checkbox, styled as a slider).
         The knob is a real element (not an ::after pseudo) so it relies only on
         standard utilities the build is known to emit. --}}
    <label class="relative inline-flex h-6 w-11 cursor-pointer items-center">
        <input type="checkbox" wire:model.live="values.{{ $key }}" class="peer sr-only">
        <span class="absolute inset-0 rounded-full bg-gray-200 transition-colors peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-500 dark:bg-gray-600"></span>
        <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
    </label>
@elseif ($type === 'bool')
    <x-gp247::checkbox wire:model.live="values.{{ $key }}" />
@elseif ($type === 'number')
    <input type="number" wire:model.live.blur="values.{{ $key }}"
        class="w-28 rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
@elseif ($type === 'select')
    <select wire:model.live="values.{{ $key }}"
        class="w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
        @foreach ($options ?? [] as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>
@else
    <input type="text" wire:model.live.blur="values.{{ $key }}"
        class="w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
@endif
