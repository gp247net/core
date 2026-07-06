{{--
    Settings table for a key/value admin_config group (ADR-005). Two columns —
    "Setting | Value" — matching the legacy admin look. Values edit inline and
    persist live (checkbox toggle / number-blur / text-blur), no submit button.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-005
    @aidlc-adr ADR-005

    Variables:
      - $configs (Collection of AdminConfig)
      - $heading (string) — first column header
      - $types (array<string,string>) — key => bool|number|text
--}}
<div class="max-w-3xl">
    {{-- Save feedback is shown by the global top-right notifications block
         (<x-gp247::toast>), so there is no inline notice pushing the layout. --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $heading }}</th>
                    <th class="w-48 px-5 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.core.value') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($configs as $i => $config)
                    @php $type = $types[$config->key] ?? 'text'; @endphp
                    <tr wire:key="cfg-{{ $config->key }}" class="{{ $i % 2 ? 'bg-gray-50/50 dark:bg-gray-800/40' : 'bg-white dark:bg-gray-800' }}">
                        <td class="px-5 py-3 align-middle text-sm text-gray-700 dark:text-gray-200">
                            {!! $config->detail ? gp247_language_render($config->detail) : e($config->key) !!}
                        </td>
                        <td class="px-5 py-3 align-middle">
                            @include('gp247-admin::partials.config-field', ['key' => $config->key, 'type' => $type])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.core.no_settings') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
