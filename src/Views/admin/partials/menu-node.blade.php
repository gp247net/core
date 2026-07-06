{{--
    One menu node row + its children (recursive). Used by the menu manager.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003
    @aidlc-adr ADR-005

    Variables: $node (AdminMenu), $grouped (by parent_id), $depth (int).
--}}
<li wire:key="menu-{{ $node->id }}" class="py-2" data-gp247-item data-id="{{ $node->id }}" draggable="true">
    <div class="flex items-center justify-between" style="padding-left: {{ $depth * 1.5 }}rem">
        <span class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
            <i class="fas fa-grip-vertical cursor-move text-gray-300 dark:text-gray-600" title="{{ gp247_language_render('admin.core.drag_to_reorder') }}"></i>
            @if ($node->icon)<i class="{{ $node->icon }} w-4 text-center text-gray-400"></i>@endif
            <span>{!! gp247_language_render($node->title) !!}</span>
            @if ($node->uri)<code class="text-xs text-gray-400">{{ $node->uri }}</code>@endif
        </span>
        <span class="flex items-center gap-1">
            <x-gp247::button size="sm" variant="ghost" href="{{ gp247_route_admin('admin_menu.edit', ['id' => $node->id]) }}" wire:navigate>
                <i class="fas fa-edit"></i>
            </x-gp247::button>
            <x-gp247::button size="sm" variant="ghost" wire:click="delete({{ $node->id }})" wire:confirm="{{ gp247_language_render('admin.menu.confirm_delete') }}">
                <i class="fas fa-trash-alt text-red-600"></i>
            </x-gp247::button>
        </span>
    </div>

    @if (! empty($grouped[$node->id]))
        <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700" data-gp247-sortable data-parent="{{ $node->id }}">
            @foreach ($grouped[$node->id] as $child)
                @include('gp247-admin::partials.menu-node', ['node' => $child, 'grouped' => $grouped, 'depth' => $depth + 1])
            @endforeach
        </ul>
    @endif
</li>
