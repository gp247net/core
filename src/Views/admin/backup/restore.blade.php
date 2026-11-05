{{--
    Restore screen (R0 replace · R0 add back deleted records · R1 configuration · R2 whole database).
    Order: archive → level → backup password / recovery key → re-confirm sign-in → preview → type
    the confirmation → follow the job → undo while the old tables are kept.

    @aidlc-unit backup-admin
    @aidlc-story US-backup-restore-table-restore, US-backup-restore-deleted-records, US-backup-restore-config-restore, US-backup-restore-full-database
    @aidlc-adr backup-restore_shadow-table-swap

    Variables: $enabled, $archives (Collection), $selected (row|null), $groups (key => DataGroup),
    $preview (array|null), $last (array|null), $undoPoint (array|null), $job (array|null), $stepUpNeedsCode, $tickUrl.
--}}
@php($inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100')
<div class="space-y-6" data-testid="backup-restore-page">
    @if ($notice)
        <x-gp247::alert :type="$noticeType" data-testid="backup-restore-notice">{{ $notice }}</x-gp247::alert>
    @endif

    {{-- Undo / last result --}}
    @if ($undoPoint)
        <x-gp247::alert type="info" data-testid="backup-restore-undo-point">
            {{ gp247_language_render('admin.backup.restore.undo_until', ['until' => $undoPoint['undo_until'] ?? '', 'tables' => count((array) ($undoPoint['tables'] ?? []))]) }}
            <div class="mt-2"><x-gp247::button size="sm" variant="warning" wire:click="undo" data-testid="backup-restore-undo">{{ gp247_language_render('admin.backup.restore.undo') }}</x-gp247::button></div>
        </x-gp247::alert>
    @endif
    @if ($last)
        <p class="text-sm text-gray-600 dark:text-gray-300" data-testid="backup-restore-last">{{ gp247_language_render('admin.backup.restore.last', ['status' => gp247_language_render('admin.backup.restore.status_' . preg_replace('/[^a-z_]/', '', (string) ($last['status'] ?? ''))), 'archive' => (string) ($last['archive_id'] ?? '')]) }}</p>
    @endif

    @if ($job && $job['type'] === 'restore')
        <x-gp247::card :title="gp247_language_render('admin.backup.job.restore')" data-testid="backup-restore-job">
            <p class="text-sm text-gray-700 dark:text-gray-200">
                @if ($job['percent'] !== null){{ $job['percent'] }}% · @endif{{ $job['phase'] }}
            </p>
            <x-gp247::button size="sm" variant="secondary" class="mt-2" wire:click="cancelRestore" data-testid="backup-restore-cancel">{{ gp247_language_render('admin.backup.job.cancel') }}</x-gp247::button>
        </x-gp247::card>
    @endif
    @include('gp247-admin::backup.partials.runner', ['tickUrl' => $tickUrl, 'active' => $job && $job['type'] === 'restore'])

    <x-gp247::card :title="gp247_language_render('admin.backup.restore.title')" :subtitle="gp247_language_render('admin.backup.restore.subtitle')">
        <x-slot:actions>
            <x-gp247::button variant="secondary" size="sm" :href="gp247_route_admin('admin.backup.archives')" data-testid="backup-restore-back">{{ gp247_language_render('admin.backup.title') }}</x-gp247::button>
        </x-slot:actions>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.backup.restore.archive') }}</label>
                <select wire:model.live="archiveId" class="{{ $inputCls }}" data-testid="backup-restore-archive">
                    <option value="">—</option>
                    @foreach ($archives as $archive)
                        <option value="{{ $archive->id }}">{{ $archive->created_at }} · {{ gp247_language_render('admin.backup.kind.' . $archive->kind) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.backup.restore.level') }}</label>
                <select wire:model.live="level" class="{{ $inputCls }}" data-testid="backup-restore-level">
                    @foreach (['replace', 'undelete', 'config', 'full'] as $value)
                        @if ($value !== 'full' || ($selected && $selected->kind === 'full'))
                            <option value="{{ $value }}">{{ gp247_language_render('admin.backup.restore.level_' . $value) }}</option>
                        @endif
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.restore.level_' . $level . '_help') }}</p>
            </div>

            @if ($level === 'replace')
                <div class="grid grid-cols-2 gap-2" style="grid-column: 1 / -1" data-testid="backup-restore-groups">
                    @foreach ($groups as $key => $group)
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <x-gp247::checkbox wire:model.live="groups" value="{{ $key }}" data-testid="backup-restore-group-{{ $key }}" />
                            {{ gp247_language_render($group->label) === $group->label ? \Illuminate\Support\Str::headline($key) : gp247_language_render($group->label) }}
                        </label>
                    @endforeach
                </div>
            @elseif ($level === 'undelete')
                <div style="grid-column: 1 / -1">
                    <x-gp247::input type="text" name="undeleteTable" wire:model.live.debounce.500ms="undeleteTable"
                        :label="gp247_language_render('admin.backup.restore.undelete_table')" data-testid="backup-restore-undelete-table" />
                </div>
            @endif

            <x-gp247::input type="password" name="restoreSecret" wire:model="restoreSecret" autocomplete="off"
                :label="gp247_language_render($restoreWithRecovery ? 'admin.backup.secret.recovery' : 'admin.backup.secret.password')" data-testid="backup-restore-secret" />
            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <x-gp247::checkbox wire:model.live="restoreWithRecovery" data-testid="backup-restore-use-recovery" />
                {{ gp247_language_render('admin.backup.secret.use_recovery') }}
            </label>
            <div style="grid-column: 1 / -1">
                <x-gp247::button wire:click="preview" :disabled="!$enabled || $archiveId === ''" data-testid="backup-restore-preview">{{ gp247_language_render('admin.backup.restore.preview') }}</x-gp247::button>
            </div>
        </div>
    </x-gp247::card>

    @if ($preview)
        <x-gp247::card :title="gp247_language_render('admin.backup.restore.preview_title')" data-testid="backup-restore-preview-result">
            <div class="space-y-4">
                @foreach ((array) ($preview['refusals'] ?? []) as $refusal)
                    <x-gp247::alert type="error" data-testid="backup-restore-refusal-{{ $refusal }}">{{ gp247_language_render('admin.backup.refused.' . $refusal) }}</x-gp247::alert>
                @endforeach
                @php($compat = (array) ($preview['compatibility'] ?? []))
                @if (($compat['status'] ?? '') !== '' && ($compat['status'] ?? '') !== 'same')
                    <x-gp247::alert :type="($compat['status'] ?? '') === 'newer' ? 'error' : 'info'" data-testid="backup-restore-compat">{{ gp247_language_render('admin.backup.restore.compat_' . ($compat['status'] ?? 'unknown')) }}</x-gp247::alert>
                @endif
                @if (!empty($preview['credential_tables']))
                    <x-gp247::alert type="warning" data-testid="backup-restore-credentials">{{ gp247_language_render('admin.backup.restore.credentials', ['tables' => implode(', ', (array) $preview['credential_tables'])]) }}</x-gp247::alert>
                @endif

                @if (!empty($preview['tables']))
                    <x-gp247::table>
                        <x-slot:head>
                            <tr>
                                @foreach (['table', 'rows_site', 'rows_archive', 'rows_new', 'columns'] as $column)
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.restore.col_' . $column) }}</th>
                                @endforeach
                            </tr>
                        </x-slot:head>
                        @foreach ((array) $preview['tables'] as $table)
                            <tr data-testid="backup-restore-preview-table">
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ $table['name'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $table['rows_site'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $table['rows_archive'] }}</td>
                                <td class="px-4 py-3 text-sm {{ ($table['rows_new_since_backup'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-600 dark:text-gray-300' }}" data-testid="backup-restore-preview-lost">{{ $table['rows_new_since_backup'] ?? 0 }}</td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ implode(', ', array_merge((array) ($table['columns_ignored'] ?? []), (array) ($table['columns_defaulted'] ?? []))) ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </x-gp247::table>
                @endif

                @if (!empty($preview['config_changes']))
                    <div class="text-sm text-gray-700 dark:text-gray-200" data-testid="backup-restore-config-changes">
                        <p class="font-medium">{{ gp247_language_render('admin.backup.restore.config_changes', ['count' => count((array) $preview['config_changes'])]) }}</p>
                        <ul class="mt-1 space-y-1 text-xs">
                            @foreach (array_slice((array) $preview['config_changes'], 0, 50) as $change)
                                <li><span class="font-mono">{{ $change['key'] }}</span> · {{ $change['change'] }} @if (!$change['secret'] && $change['change'] === 'changed') ({{ $change['site'] }} → {{ $change['archive'] }}) @endif</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (!empty($preview['undelete']))
                    @php($undelete = (array) $preview['undelete'])
                    <div class="space-y-3" data-testid="backup-restore-undelete">
                        <p class="text-sm text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.backup.restore.undelete_missing', ['count' => $undelete['missing'] ?? 0]) }}</p>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <x-gp247::checkbox wire:model.live="undeleteAll" data-testid="backup-restore-undelete-all" />
                            {{ gp247_language_render('admin.backup.restore.undelete_all') }}
                        </label>
                        @foreach ((array) ($undelete['sample'] ?? []) as $row)
                            @php($key = (string) ($row[$undelete['key'] ?? 'id'] ?? ''))
                            <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                <x-gp247::checkbox wire:model="undeleteKeys" value="{{ $key }}" data-testid="backup-restore-undelete-key" />
                                <span class="font-mono">{{ $key }}</span>
                                <span class="truncate">{{ implode(' · ', array_map(fn ($v) => is_scalar($v) ? \Illuminate\Support\Str::limit((string) $v, 40) : '', array_slice($row, 0, 4))) }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif

                @if (empty($preview['refusals']))
                    <form wire:submit="start" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <p class="text-sm text-gray-600 dark:text-gray-300" style="grid-column: 1 / -1">{{ gp247_language_render('admin.backup.restore.confirm_help', ['code' => substr($archiveId, -6)]) }}</p>
                        <x-gp247::input type="text" name="confirmText" wire:model="confirmText" autocomplete="off"
                            :label="gp247_language_render('admin.backup.restore.confirm')" :error="$errors->first('confirmText')" data-testid="backup-restore-confirm" />
                        <div class="flex items-end">
                            <x-gp247::button type="submit" variant="danger" data-testid="backup-restore-start">{{ gp247_language_render('admin.backup.restore.start') }}</x-gp247::button>
                        </div>
                    </form>
                @endif
            </div>
        </x-gp247::card>
    @endif

    @include('gp247-admin::backup.partials.step-up')
</div>
