{{--
    API connection screen (ADR-005) — two-column layout mirroring the legacy
    AdminApiConnectionController screen: create/edit form (left) and the global
    api_connection_required toggle + route list + usage hint + connections table
    (right). CRUD + toggle persist through the component's Layer-2 gated methods.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-005
    @aidlc-adr ADR-001, ADR-005

    Variables: $rows (paginator), $listCore (string[]), $listFront (string[]).
--}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-12">

    {{-- Left: create / edit form --}}
    <div class="lg:col-span-5">
        <x-gp247::card>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">
                    @if ($editingId)
                        <i class="fas fa-edit"></i> {{ gp247_language_render('admin.api_connection.edit') }}
                    @else
                        <i class="fas fa-plus"></i> {{ gp247_language_render('admin.api_connection.create') }}
                    @endif
                </h3>
                @if ($editingId)
                    <x-gp247::button variant="secondary" size="sm" wire:click="resetForm">
                        <i class="fas fa-list"></i> {{ gp247_language_render('admin.back_list') }}
                    </x-gp247::button>
                @endif
            </div>

            <form wire:submit="save" class="space-y-4">
                <x-gp247::input wire:model="description" :label="gp247_language_render('admin.api_connection.description')"
                    :error="$errors->first('description')" />

                <x-gp247::input wire:model="apiconnection" :label="gp247_language_render('admin.api_connection.connection')"
                    :error="$errors->first('apiconnection')" />

                <div class="space-y-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ gp247_language_render('admin.api_connection.apikey') }}
                    </label>
                    <div class="flex gap-2">
                        <input type="text" wire:model="apikey"
                            class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <x-gp247::button variant="secondary" size="sm" wire:click="generateKey" title="Generate">
                            <i class="fas fa-sync-alt"></i>
                        </x-gp247::button>
                    </div>
                    @if ($errors->first('apikey'))
                        <p class="text-sm text-red-600 dark:text-red-400">{{ $errors->first('apikey') }}</p>
                    @endif
                </div>

                <x-gp247::input type="date" wire:model="expire" :label="gp247_language_render('admin.api_connection.expire')"
                    :error="$errors->first('expire')" />

                <div class="flex items-center gap-3">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.api_connection.status') }}</span>
                    <label class="relative inline-flex h-6 w-11 cursor-pointer items-center">
                        <input type="checkbox" wire:model="status" class="peer sr-only">
                        <span class="absolute inset-0 rounded-full bg-gray-200 transition-colors peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-500 dark:bg-gray-600"></span>
                        <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                    </label>
                </div>

                <div class="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                    <x-gp247::button variant="secondary" size="sm" type="button" wire:click="resetForm">
                        {{ gp247_language_render('action.reset') }}
                    </x-gp247::button>
                    <x-gp247::button variant="primary" size="sm" type="submit">
                        {{ gp247_language_render('action.submit') }}
                    </x-gp247::button>
                </div>
            </form>
        </x-gp247::card>
    </div>

    {{-- Right: global toggle + route list + usage + connections table --}}
    <div class="lg:col-span-7">
        <x-gp247::card>
            <div class="mb-4 flex items-center gap-3">
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.api_connection.service') }}</span>
                <label class="relative inline-flex h-6 w-11 cursor-pointer items-center">
                    <input type="checkbox" wire:model.live="apiConnectionRequired" class="peer sr-only">
                    <span class="absolute inset-0 rounded-full bg-gray-200 transition-colors peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-500 dark:bg-gray-600"></span>
                    <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                </label>
            </div>

            <div class="mb-3 rounded-lg border-l-4 border-blue-500 bg-blue-50 px-3 py-2 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                {!! gp247_language_render('admin.api_connection.api_connection_required_help') !!}
            </div>

            @if (count($listCore) || count($listFront))
                <div class="mb-2 grid grid-cols-2 gap-2">
                    @if (count($listCore))
                        <div class="rounded-lg border border-dashed border-gray-300 px-3 py-2 dark:border-gray-600">
                            <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">List API core:</p>
                            @foreach ($listCore as $item)
                                <code class="block text-xs text-gray-500 dark:text-gray-400">{{ $item }}</code>
                            @endforeach
                        </div>
                    @endif
                    @if (count($listFront))
                        <div class="rounded-lg border border-dashed border-gray-300 px-3 py-2 dark:border-gray-600">
                            <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">List API front:</p>
                            @foreach ($listFront as $item)
                                <code class="block text-xs text-gray-500 dark:text-gray-400">{{ $item }}</code>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">ID</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.api_connection.description') }}</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.api_connection.connection') }}</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.api_connection.expire') }}</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.api_connection.status') }}</th>
                            <th class="px-3 py-2 text-right font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('action.title') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($rows as $row)
                            <tr wire:key="api-{{ $row->id }}" class="{{ $editingId === (int) $row->id ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $row->id }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row->description }}</td>
                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $row->apiconnection }}</td>
                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $row->expire }}</td>
                                <td class="px-3 py-2">
                                    @if ($row->status)
                                        <x-gp247::badge color="green">{{ gp247_language_render('admin.on') }}</x-gp247::badge>
                                    @else
                                        <x-gp247::badge color="red">{{ gp247_language_render('admin.off') }}</x-gp247::badge>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <div class="inline-flex gap-1">
                                        <x-gp247::button variant="secondary" size="sm" wire:click="editRow('{{ $row->id }}')">
                                            <i class="fas fa-edit"></i>
                                        </x-gp247::button>
                                        <x-gp247::button variant="danger" size="sm"
                                            wire:click="deleteRow('{{ $row->id }}')"
                                            wire:confirm="{{ gp247_language_render('admin.confirm_delete') }}">
                                            <i class="fas fa-trash-alt"></i>
                                        </x-gp247::button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.no_records') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                {{ $rows->links() }}
            </div>
        </x-gp247::card>
    </div>
</div>
