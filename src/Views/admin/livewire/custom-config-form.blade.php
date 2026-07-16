{{--
    Free-form custom config editor (admin_custom_config, ADR-005): the modern port
    of the legacy "Cấu hình tùy chỉnh" tab. Columns — Detail | Key | Value | (action).
    Values edit inline and persist live (text-blur); a trailing row adds a new config
    (detail/key/value); each row can be deleted. Social links appear here as seeded
    defaults — they are not a special form.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-005
    @aidlc-adr ADR-001, ADR-005

    Variables:
      - $rows (Collection of AdminConfig)
      - $heading (string)
--}}
<div class="max-w-4xl">
    {{-- Save feedback is shown by the global top-right notifications block
         (<x-gp247::toast>), so there is no inline notice pushing the layout. --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.admin_custom_config.add_new_detail') }}</th>
                    <th class="px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.admin_custom_config.add_new_key') }}</th>
                    <th class="px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.admin_custom_config.add_new_value') }}</th>
                    <th class="w-16 px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($rows as $i => $row)
                    <tr wire:key="cfg-{{ $row->key }}" class="{{ $i % 2 ? 'bg-gray-50/50 dark:bg-gray-800/40' : 'bg-white dark:bg-gray-800' }}">
                        <td class="px-5 py-3 align-middle text-sm text-gray-700 dark:text-gray-200">
                            {{ $row->detail ? gp247_language_render($row->detail) : $row->key }}
                        </td>
                        <td class="px-5 py-3 align-middle text-sm text-gray-500 dark:text-gray-400">
                            {{ $row->key }}
                        </td>
                        <td class="px-5 py-3 align-middle">
                            <input type="text" wire:model.live.blur="values.{{ $row->key }}"
                                class="w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        </td>
                        <td class="px-5 py-3 align-middle text-right">
                            <x-gp247::button variant="danger" size="sm"
                                wire:click="deleteKey('{{ $row->key }}')"
                                wire:confirm="{{ gp247_language_render('admin.confirm_delete') }}">
                                <i class="fas fa-trash-alt"></i>
                            </x-gp247::button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.no_settings') }}</td></tr>
                @endforelse

                {{-- Add-new row --}}
                <tr class="border-t-2 border-gray-200 bg-gray-50/60 dark:border-gray-600 dark:bg-gray-800/60">
                    <td class="px-5 py-3 align-top">
                        <input type="text" wire:model="newDetail"
                            placeholder="{{ gp247_language_render('admin.admin_custom_config.add_new_detail') }}"
                            class="w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    </td>
                    <td class="px-5 py-3 align-top">
                        <input type="text" wire:model="newKey"
                            placeholder="{{ gp247_language_render('admin.admin_custom_config.add_new_key') }}"
                            class="w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    </td>
                    <td class="px-5 py-3 align-top">
                        <input type="text" wire:model="newValue"
                            placeholder="{{ gp247_language_render('admin.admin_custom_config.add_new_value') }}"
                            class="w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    </td>
                    <td class="px-5 py-3 align-top text-right">
                        <x-gp247::button variant="primary" size="sm" wire:click="addNew">
                            <i class="fas fa-plus"></i>
                        </x-gp247::button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
