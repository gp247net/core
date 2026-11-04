{{--
    GP247 "+ Create new" button for two-column screens (form + list).

    Shown only while a record is being edited; it calls the screen's reset action
    (`cancelEdit` on ResourcePanel / MenuManager, `resetForm` elsewhere), which
    returns the form to an empty create form without leaving the page, so the list
    keeps its page, keyword and sort. Place it in the `actions` slot of
    <x-gp247::card> (or in a screen's own form header). The foot of the form keeps
    only the submit button — no Reset / Cancel.

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-two-panel-state-preservation
    @aidlc-adr ADR-admin-shell-rbac-two-panel-state-preservation

    @props array
      - editing (mixed): the screen's editing id; the button renders only when it is set.
      - action (string): Livewire method to call. Default "cancelEdit".
      - label (string|null): button text. Default gp247_language_render('action.create').
    Extra attributes (data-testid…) pass through to the button.
--}}
@props([
    'editing' => null,
    'action' => 'cancelEdit',
    'label' => null,
])

@if ($editing !== null && $editing !== '')
    <x-gp247::button size="sm" variant="outline" wire:click="{{ $action }}" {{ $attributes }}>
        <i class="fas fa-plus"></i> {{ $label ?? gp247_language_render('action.create') }}
    </x-gp247::button>
@endif
