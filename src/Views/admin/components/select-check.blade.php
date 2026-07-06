{{--
    Row-selection checkbox bound to the component's `selected` array (ADR-005).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002
    @aidlc-adr ADR-005

    @props
      - value (int|string): row id added to `selected` when checked.
--}}
@props(['value'])

<input type="checkbox" wire:model.live="selected" value="{{ $value }}"
    {{ $attributes->merge(['class' => 'rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600']) }}>
