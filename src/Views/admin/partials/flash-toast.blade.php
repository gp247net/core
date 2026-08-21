{{--
    Session-flash → toast bridge (ADR admin-shell_flash-toast-bridge).

    The <x-gp247::toast> component only reacts to the JS `notify` browser event
    (dispatched by Livewire GP247AdminComponent::notify). Controller-rendered,
    non-Livewire admin screens report results via session flash
    (redirect()->with('error'|'success'|'warning'|'info', ...)) which previously
    had no path to the toast layer, so those messages were silently dropped
    (RISK-TECH-admin-flash-not-surfaced) — e.g. the admin create-order screen
    hard-blocks an over-stock line but showed no warning.

    This shared bridge, included once by the admin layouts, re-emits any present
    flash message as a `notify` event on load so every non-Livewire screen
    surfaces its result through the same toast UI. Callers keep using plain
    ->with(...); they do not bridge locally (P3 ui-tailadmin — shared infra).

    @aidlc-unit admin-shell
    @aidlc-story US-SADM-order-create-stock-feedback
    @aidlc-adr admin-shell_flash-toast-bridge
--}}
@php
    // WHY: map Laravel flash keys to the toast component's inherited types
    // (success|error|warning|info). Only these keys are surfaced; other session
    // data is left untouched.
    $gp247FlashToastMap = ['error' => 'error', 'warning' => 'warning', 'success' => 'success', 'info' => 'info'];
@endphp
@foreach ($gp247FlashToastMap as $gp247FlashKey => $gp247ToastType)
    @if (session($gp247FlashKey))
        {{-- WHY: @js() safely JSON-encodes the (i18n) message into the JS context,
             avoiding XSS from any interpolated value (NFR-SEC-004). Alpine $dispatch
             bubbles the CustomEvent to window, where <x-gp247::toast> listens. --}}
        <div
            data-testid="admin-flash-toast-{{ $gp247ToastType }}"
            x-data
            x-init="$dispatch('notify', { type: @js($gp247ToastType), message: @js(session($gp247FlashKey)) })"
            class="hidden"
            aria-hidden="true"
        ></div>
    @endif
@endforeach
