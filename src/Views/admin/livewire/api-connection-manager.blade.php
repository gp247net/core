{{--
    API connection screen (ADR-005). Left column: the api_connection_required toggle,
    the create/edit form and the connections table. Right column: the REST API master
    switch, the route boxes (v1/v2 × core/front, each with its own group switch; all
    locked when GP247_API_MODE is off) and the link to the API guide.
    CRUD and toggles persist through the component's Layer-2 gated methods.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-005, US-CMP-api-admin-switch, US-CMP-api-docs-link-from-admin
    @aidlc-adr ADR-001, ADR-005, compat-foundation_rest-api-hardening

    Variables: $rows (paginator), $routeGroups (list of {key, switch, title, items, on}), $docsGuide (string|null),
    plus the component's $apiSwitches / $apiServerEnabled.
--}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-12">

    {{-- Left: API Connection toggle + create / edit form + connections table --}}
    <div class="space-y-6 lg:col-span-5">
        <x-gp247::card>
            <div class="mb-4 flex items-center gap-3">
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.api_connection.service') }}</span>
                <label class="relative inline-flex h-6 w-11 cursor-pointer items-center">
                    <input type="checkbox" wire:model.live="apiConnectionRequired" class="peer sr-only">
                    <span class="absolute inset-0 rounded-full bg-gray-200 transition-colors peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-500 dark:bg-gray-600"></span>
                    <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                </label>
            </div>

            <div class="rounded-lg border-l-4 border-blue-500 bg-blue-50 px-3 py-2 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                {!! gp247_language_render('admin.api_connection.api_connection_required_help') !!}
            </div>
        </x-gp247::card>

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
                    @if ($editingId)
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.api_connection.apikey_keep_hint') }}</p>
                    @endif
                </div>

                @if ($revealedKey)
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-600 dark:bg-amber-900/20 dark:text-amber-200"
                        data-testid="api-connection-key-once">
                        <p class="font-medium">{{ gp247_language_render('admin.api_connection.apikey_once') }}</p>
                        <code class="mt-1 block break-all font-mono">{{ $revealedKey }}</code>
                    </div>
                @endif

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

        <x-gp247::card>
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
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

    {{-- Right: REST API master switch + route boxes (each with its group switch) + docs --}}
    <div class="lg:col-span-7">
        <x-gp247::card>
            <div class="mb-4 border-b border-gray-100 pb-4 dark:border-gray-700">
                @unless ($apiServerEnabled)
                    <div class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-600 dark:bg-amber-900/20 dark:text-amber-200"
                        data-testid="api-connection-switch-locked">
                        {{ gp247_language_render('admin.api_connection.switch_locked') }}
                    </div>
                @endunless

                <div class="flex items-center gap-3">
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.api_connection.switch_all') }}</span>
                    <label class="relative inline-flex h-6 w-11 cursor-pointer items-center">
                        <input type="checkbox" class="peer sr-only" data-testid="api-connection-switch-all"
                            @checked($apiSwitches['api_enabled'] ?? true) @disabled(!$apiServerEnabled)
                            wire:change="setApiSwitch('api_enabled', $event.target.checked)">
                        <span class="absolute inset-0 rounded-full bg-gray-200 transition-colors peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-500 peer-disabled:cursor-not-allowed peer-disabled:opacity-50 dark:bg-gray-600"></span>
                        <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                    </label>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.api_connection.switch_help') }}</p>
            </div>


            <div class="mb-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($routeGroups as $group)
                    {{-- A group box shows even without routes so its switch stays reachable; "other" only when a plugin adds routes. --}}
                    @if ($group['switch'] || count($group['items']))
                        <div class="rounded-lg border border-dashed border-gray-300 px-3 py-2 dark:border-gray-600"
                            data-testid="api-connection-routes-{{ $group['key'] }}">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                                    {{ $group['title'] }}
                                    @unless ($group['on'])
                                        <span class="font-medium text-red-600">({{ gp247_language_render('admin.api_connection.group_off') }})</span>
                                    @endunless
                                </p>
                                @if ($group['switch'])
                                    <label class="relative inline-flex h-6 w-11 cursor-pointer items-center">
                                        <input type="checkbox" class="peer sr-only" data-testid="api-connection-switch-{{ $group['key'] }}"
                                            @checked($apiSwitches[$group['switch']] ?? true) @disabled(!$apiServerEnabled)
                                            wire:change="setApiSwitch('{{ $group['switch'] }}', $event.target.checked)">
                                        <span class="absolute inset-0 rounded-full bg-gray-200 transition-colors peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-500 peer-disabled:cursor-not-allowed peer-disabled:opacity-50 dark:bg-gray-600"></span>
                                        <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                                    </label>
                                @endif
                            </div>
                            <div class="mt-1 {{ $group['on'] ? '' : 'opacity-70' }}">
                                @foreach ($group['items'] as $item)
                                    <code class="block break-all text-xs text-gray-500 dark:text-gray-400 {{ $group['on'] ? '' : 'line-through' }}">{{ $item }}</code>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
            <p class="text-xs italic text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.api_connection.route_list_note') }}</p>

            @if ($docsGuide)
                <div class="mt-4 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                    <a href="{{ $docsGuide }}" target="_blank" rel="noopener noreferrer" data-testid="api-connection-docs-link"
                        class="text-blue-600 hover:underline dark:text-blue-400"><i class="fas fa-book"></i> {{ gp247_language_render('admin.api_connection.docs_guide') }}</a>
                </div>
            @endif
        </x-gp247::card>
    </div>
</div>
