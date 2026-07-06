{{--
    Right-aligned per-row action group (ADR-005): an optional Edit link and a
    Delete button wired to the component's delete($id). When locked, the delete
    affordance is replaced by a muted "locked" tag. Extra actions may be added
    via the default slot (rendered before Edit).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002
    @aidlc-adr ADR-005

    @props
      - edit (string|null): href for the Edit link (omitted when null).
      - deleteId (int|string|null): id passed to delete(); omitted when null.
      - deleteConfirm (string): confirm prompt for delete.
      - locked (bool): row is protected; hides delete and shows a "locked" tag.
    @slot default: extra leading action buttons (optional).
--}}
@props([
    'edit' => null,
    'deleteId' => null,
    'deleteConfirm' => null,
    'locked' => false,
])

@php $deleteConfirm = $deleteConfirm ?? gp247_language_render('admin.core.confirm_delete'); @endphp

<div class="flex items-center justify-end gap-1">
    {{ $slot }}

    @if ($edit)
        <x-gp247::button size="sm" variant="ghost" href="{{ $edit }}" wire:navigate title="{{ gp247_language_render('admin.core.edit') }}">
            <i class="fas fa-edit"></i>
        </x-gp247::button>
    @endif

    @if ($locked)
        <span class="px-2 text-xs text-gray-400">{{ gp247_language_render('admin.core.locked') }}</span>
    @elseif (!is_null($deleteId))
        <x-gp247::button size="sm" variant="ghost" wire:click="delete('{{ $deleteId }}')" wire:confirm="{{ $deleteConfirm }}" title="{{ gp247_language_render('admin.core.delete') }}">
            <i class="fas fa-trash-alt text-red-600"></i>
        </x-gp247::button>
    @endif
</div>
