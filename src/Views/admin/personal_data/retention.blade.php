{{--
    "Data retention" screen (US-personal-data-retention-policies): one row per kind of personal data —
    on / off, days kept, last run, rows handled, first allowed clean; "run now"; confirmation (count,
    password, backup) before an item that removes business data is turned on or shortened.
    Only class combinations already in the prebuilt admin bundle are used (gp247.md §3a).

    @aidlc-unit personal-data-retention
    @aidlc-story US-personal-data-retention-policies, US-personal-data-retention-lazy-run, US-personal-data-retention-order-anonymise, US-personal-data-retention-inactive-accounts
    @aidlc-adr personal-data-retention_retention-registry, personal-data-retention_sweep-runner

    Variables: $ready (bool), $items (list of rows), $noticeDays, $firstCleanDays, $confirmAction, $accountsAction, $stepUpMinutes.
--}}
@php($inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100')
<div class="space-y-6" data-testid="personal-data-retention-page">
    @unless ($ready)
        <x-gp247::alert type="warning">{{ gp247_language_render('admin.personal_data.not_ready') }}</x-gp247::alert>
    @else
        <x-gp247::alert type="info">{{ gp247_language_render('admin.personal_data.retention.help', ['days' => $firstCleanDays]) }}</x-gp247::alert>

        @if ($confirmKey)
            <x-gp247::card :title="gp247_language_render('admin.personal_data.retention.confirm_title')" data-testid="personal-data-retention-confirm">
                <div class="space-y-3 text-sm text-gray-700 dark:text-gray-200">
                    <x-gp247::alert type="warning">
                        {{ gp247_language_render($confirmAction === $accountsAction ? 'admin.personal_data.retention.confirm_accounts' : 'admin.personal_data.retention.confirm_records', ['count' => $confirmCount, 'days' => $noticeDays]) }}
                    </x-gp247::alert>
                    <p>{{ gp247_language_render('admin.personal_data.retention.confirm_backup') }}</p>
                    @foreach ($preChangeMessages as $message)
                        <p class="text-gray-500 dark:text-gray-400" data-testid="personal-data-retention-pre-change">{{ $message }}</p>
                    @endforeach
                    <div class="flex flex-wrap gap-2">
                        <x-gp247::button size="sm" variant="danger" wire:click="confirmRisky" data-testid="personal-data-retention-confirm-submit">{{ gp247_language_render('admin.personal_data.retention.confirm') }}</x-gp247::button>
                        <x-gp247::button size="sm" variant="secondary" wire:click="cancelRisky" data-testid="personal-data-retention-confirm-cancel">{{ gp247_language_render('admin.step_up.cancel') }}</x-gp247::button>
                    </div>
                </div>
            </x-gp247::card>
        @endif

        @if ($earlyKey)
            <x-gp247::card :title="gp247_language_render('admin.personal_data.retention.early_title')" data-testid="personal-data-retention-early">
                <div class="space-y-3 text-sm text-gray-700 dark:text-gray-200">
                    <x-gp247::alert type="warning">{{ gp247_language_render('admin.personal_data.retention.early_help') }}</x-gp247::alert>
                    <div class="flex flex-wrap gap-2">
                        <x-gp247::button size="sm" variant="danger" wire:click="runEarly" data-testid="personal-data-retention-early-submit">{{ gp247_language_render('admin.personal_data.retention.early_confirm') }}</x-gp247::button>
                        <x-gp247::button size="sm" variant="secondary" wire:click="cancelEarly" data-testid="personal-data-retention-early-cancel">{{ gp247_language_render('admin.step_up.cancel') }}</x-gp247::button>
                    </div>
                </div>
            </x-gp247::card>
        @endif

        <x-gp247::card :title="gp247_language_render('admin.personal_data.retention.title')">
            <x-gp247::table :empty="$items === [] ? gp247_language_render('admin.personal_data.retention.empty') : null">
                <x-slot:head>
                    <tr>
                        @foreach (['item', 'enabled', 'days', 'last_run', 'rows', 'actions'] as $column)
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.personal_data.retention.col.' . $column) }}</th>
                        @endforeach
                    </tr>
                </x-slot:head>
                @foreach ($items as $row)
                    <tr wire:key="pdret-{{ $row['key'] }}" data-testid="personal-data-retention-row">
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                            <span class="font-medium">{{ $row['label'] }}</span>
                            @if ($row['risky'])
                                <x-gp247::badge color="amber">{{ gp247_language_render('admin.personal_data.retention.risky') }}</x-gp247::badge>
                            @endif
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.personal_data.retention.action.' . $row['action']) }} · {{ $row['owner'] }}</p>
                            @if ($row['waiting'] && $row['first_allowed_at'])
                                <p class="text-xs text-gray-500 dark:text-gray-400" data-testid="personal-data-retention-first">{{ gp247_language_render('admin.personal_data.retention.first_clean', ['at' => substr((string) $row['first_allowed_at'], 0, 10)]) }}</p>
                            @endif
                            @if ($row['last_error'])
                                <p class="text-xs text-red-600" data-testid="personal-data-retention-error">{{ gp247_language_render('admin.personal_data.retention.error', ['error' => class_basename($row['last_error'])]) }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <x-gp247::checkbox wire:model="enabled.{{ $row['field'] }}" value="1" data-testid="personal-data-retention-enabled" />
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                            @if ($row['fixed'])
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.personal_data.retention.fixed') }}</span>
                            @else
                                <input type="number" min="{{ $row['min'] }}" max="{{ $row['max'] }}" wire:model="days.{{ $row['field'] }}" data-testid="personal-data-retention-days" class="{{ $inputCls }}">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.personal_data.retention.range', ['min' => $row['min'], 'max' => $row['max']]) }}</p>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $row['last_run_at'] ? substr((string) $row['last_run_at'], 0, 16) : '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $row['last_rows'] }} / {{ $row['total_rows'] }}</td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2 whitespace-nowrap">
                                <x-gp247::button size="sm" variant="primary" wire:click="save('{{ $row['key'] }}')" data-testid="personal-data-retention-save">{{ gp247_language_render('admin.personal_data.retention.save') }}</x-gp247::button>
                                <x-gp247::button size="sm" variant="secondary" wire:click="runNow('{{ $row['key'] }}')" data-testid="personal-data-retention-run">{{ gp247_language_render('admin.personal_data.retention.run_now') }}</x-gp247::button>
                            </div>
                        </td>
                    </tr>
                @endforeach
                <tr data-testid="personal-data-retention-requests-row">
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                        <span class="font-medium">{{ gp247_language_render('admin.personal_data.retention.requests') }}</span>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.personal_data.retention.requests_help') }}</p>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">✓</td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.personal_data.retention.requests_period') }}</td>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3"></td>
                </tr>
            </x-gp247::table>
        </x-gp247::card>
    @endunless

    <x-gp247::step-up-box :open="$stepUpOpen" :needs-code="$this->stepUpAsksCode()" :error="$stepUpError"
        :minutes="$stepUpMinutes" testid="personal-data-retention-step-up" />
</div>
