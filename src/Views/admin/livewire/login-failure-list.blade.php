{{--
    "Login failures" screen (US-admin-shell-login-failure-list): recorded failed sign-ins,
    newest first, with filters, and the choice of recorded account groups. Administrator only.
    No password is ever stored, so none can be shown.

    @aidlc-unit admin-shell
    @aidlc-story US-admin-shell-login-failure-list
    @aidlc-adr admin-shell_login-failure-log

    Variables: $rows (paginator of stdClass), $groups (key => label code), $keepDays (int).
--}}
<div data-testid="admin-login-failures-page" class="space-y-5">
    <div>
        <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.login_failures.title') }}</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.login_failures.subtitle', ['days' => $keepDays]) }}</p>
    </div>

    <x-gp247::card :title="gp247_language_render('admin.login_failures.audiences')">
        <div class="flex flex-wrap items-center gap-4 px-5 py-4" data-testid="admin-login-failures-audiences">
            @foreach ($groups as $key => $label)
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <x-gp247::checkbox wire:model="audiences" value="{{ $key }}" data-testid="admin-login-failures-audience-{{ $key }}" />
                    {{ gp247_language_render($label) }}
                </label>
            @endforeach
            <x-gp247::button size="sm" wire:click="saveAudiences" data-testid="admin-login-failures-save">{{ gp247_language_render('admin.login_failures.save') }}</x-gp247::button>
            @if ($saved)
                <span class="text-xs text-gray-500 dark:text-gray-400" data-testid="admin-login-failures-saved">{{ gp247_language_render('admin.login_failures.saved') }}</span>
            @endif
        </div>
    </x-gp247::card>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <select wire:model.live="filterAudience" data-testid="admin-login-failures-filter-audience"
            class="rounded-lg border border-gray-300 py-2 pl-3 pr-8 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
            <option value="">{{ gp247_language_render('admin.login_failures.all_groups') }}</option>
            @foreach ($groups as $key => $label)
                <option value="{{ $key }}">{{ gp247_language_render($label) }}</option>
            @endforeach
        </select>
        <input type="text" wire:model.live.debounce.400ms="filterIp" data-testid="admin-login-failures-filter-ip"
            placeholder="{{ gp247_language_render('admin.login_failures.ip') }}"
            class="w-64 max-w-full rounded-lg border border-gray-300 py-2 pl-3 pr-8 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
        <input type="text" wire:model.live.debounce.400ms="filterIdentity" data-testid="admin-login-failures-filter-identity"
            placeholder="{{ gp247_language_render('admin.login_failures.identity') }}"
            class="w-64 max-w-full rounded-lg border border-gray-300 py-2 pl-3 pr-8 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
        <input type="date" wire:model.live="filterFrom" data-testid="admin-login-failures-filter-from" title="{{ gp247_language_render('admin.login_failures.from') }}"
            class="rounded-lg border border-gray-300 py-2 pl-3 pr-8 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
        <input type="date" wire:model.live="filterTo" data-testid="admin-login-failures-filter-to" title="{{ gp247_language_render('admin.login_failures.to') }}"
            class="rounded-lg border border-gray-300 py-2 pl-3 pr-8 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
    </div>

    <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.login_failures.empty') : null">
        <x-slot:head>
            <tr>
                @foreach (['time', 'group', 'identity', 'reason', 'channel', 'ip', 'user_agent'] as $column)
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.login_failures.' . $column) }}</th>
                @endforeach
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" wire:key="login-failure-{{ $row->id }}" data-testid="admin-login-failures-row">
                <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $row->created_at }}</td>
                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ isset($groups[$row->audience]) ? gp247_language_render($groups[$row->audience]) : $row->audience }}</td>
                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    <span class="break-all">{{ $row->identity ?? ($row->account_id !== null ? '#' . $row->account_id : '—') }}</span>
                    @if ($row->account_id !== null)
                        <span class="block text-xs text-gray-400">{{ gp247_language_render('admin.login_failures.account_exists') }}</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    <x-gp247::badge :color="$row->reason === 'throttled' ? 'red' : 'amber'">{{ gp247_language_render('admin.login_failures.reason_' . $row->reason) }}</x-gp247::badge>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $row->channel }}</td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $row->ip ?? '—' }}</td>
                <td class="max-w-xs truncate px-4 py-3 text-xs text-gray-400 dark:text-gray-500" title="{{ $row->user_agent }}">{{ $row->user_agent }}</td>
            </tr>
        @endforeach
    </x-gp247::table>

    <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
</div>
