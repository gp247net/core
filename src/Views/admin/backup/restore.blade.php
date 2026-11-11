{{--
    Restore screen (R0 replace · R0 add back deleted records · R1 configuration · R2 whole database).
    Order: archive → level → backup password / recovery key → re-confirm sign-in → preview → type
    the confirmation → follow the job → undo while the old tables are kept.

    @aidlc-unit backup-admin
    @aidlc-story US-backup-restore-table-restore, US-backup-restore-deleted-records, US-backup-restore-config-restore, US-backup-restore-full-database, US-backup-restore-import-archive, US-backup-restore-post-incident-mode
    @aidlc-adr backup-restore_shadow-table-swap

    Variables: $enabled, $archives (Collection), $selected (row|null), $dataGroups (key => DataGroup; not $groups — that name is the component property holding the ticked keys),
    $preview (array|null), $last (array|null), $undoPoint (array|null), $job (array|null), $stepUpNeedsCode, $tickUrl,
    $lastImport (array|null), $uploadMaxBytes (int) — BK-5.
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

    @if ($job && in_array($job['type'], ['restore', 'import'], true))
        <x-gp247::card :title="gp247_language_render('admin.backup.job.' . $job['type'])" data-testid="backup-restore-job">
            <p class="text-sm text-gray-700 dark:text-gray-200">
                @if ($job['percent'] !== null){{ $job['percent'] }}% · @endif{{ $job['phase'] }}
            </p>
            @if ($job['type'] === 'restore')
                <x-gp247::button size="sm" variant="secondary" class="mt-2" wire:click="cancelRestore" data-testid="backup-restore-cancel">{{ gp247_language_render('admin.backup.job.cancel') }}</x-gp247::button>
            @endif
        </x-gp247::card>
    @endif
    @include('gp247-admin::backup.partials.runner', ['tickUrl' => $tickUrl, 'active' => $job && in_array($job['type'], ['restore', 'import'], true)])
    @if ($lastImport && $lastImport['status'] !== 'running')
        <p class="text-sm {{ $lastImport['status'] === 'done' ? 'text-gray-600 dark:text-gray-300' : 'text-red-600' }}" data-testid="backup-restore-last-import">
            {{ gp247_language_render('admin.backup.import.last_' . ($lastImport['status'] === 'done' ? 'done' : 'failed'), ['archive' => (string) ($lastImport['archive_id'] ?? ''), 'reason' => (string) ($lastImport['error'] ?? '')]) }}
            @if (($lastImport['origin'] ?? null) === 'unverified') · {{ gp247_language_render('admin.backup.origin.unverified_short') }} @endif
        </p>
    @endif

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

            @if (($selected && $selected->origin === 'unverified') || (($preview['origin']['origin'] ?? null) === 'unverified'))
                <div style="grid-column: 1 / -1">
                    <x-gp247::alert type="warning" data-testid="backup-restore-origin-unverified">{{ gp247_language_render('admin.backup.origin.unverified') }}</x-gp247::alert>
                    <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <x-gp247::checkbox wire:model.live="acceptUnverified" data-testid="backup-restore-accept-unverified" />
                        {{ gp247_language_render('admin.backup.origin.accept') }}
                    </label>
                </div>
            @endif
            {{-- Mod 20261110T164834 (A1): data of columns the site no longer has is lost — confirmed on its own. --}}
            @if ($acceptColumnLoss || !empty($preview['lost_columns']))
                <div style="grid-column: 1 / -1">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <x-gp247::checkbox wire:model.live="acceptColumnLoss" data-testid="backup-restore-accept-column-loss" />
                        {{ gp247_language_render('admin.backup.restore.accept_column_loss') }}
                    </label>
                </div>
            @endif
            @if ($level === 'full')
                <div style="grid-column: 1 / -1">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <x-gp247::checkbox wire:model.live="postIncident" data-testid="backup-restore-post-incident" />
                        {{ gp247_language_render('admin.backup.post_incident.option') }}
                    </label>
                    @if ($postIncident)
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-testid="backup-restore-post-incident-help">{{ gp247_language_render('admin.backup.post_incident.option_help') }}</p>
                    @endif
                </div>
            @endif

            @if ($level === 'replace')
                <div class="grid grid-cols-1 gap-2" style="grid-column: 1 / -1" data-testid="backup-restore-groups">
                    @foreach ($dataGroups as $key => $group)
                        @include('gp247-admin::backup.partials.group-option', ['model' => 'groups', 'live' => true, 'value' => $key, 'label' => \GP247\Core\Backup\Livewire\GroupText::label($key, $group), 'help' => \GP247\Core\Backup\Livewire\GroupText::help($key, $group), 'testid' => 'backup-restore-group-' . $key])
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
                @if (!empty($preview['post_incident']))
                    <x-gp247::alert type="warning" data-testid="backup-restore-post-incident-preview">{{ gp247_language_render('admin.backup.post_incident.preview') }}</x-gp247::alert>
                @endif
                @foreach ((array) ($preview['refusals'] ?? []) as $refusal)
                    <x-gp247::alert type="error" data-testid="backup-restore-refusal-{{ $refusal }}">{{ gp247_language_render('admin.backup.refused.' . $refusal) }}</x-gp247::alert>
                @endforeach
                @php($compat = (array) ($preview['compatibility'] ?? []))
                @if (($compat['status'] ?? '') !== '' && ($compat['status'] ?? '') !== 'same')
                    <x-gp247::alert :type="($compat['status'] ?? '') === 'newer' ? 'error' : 'info'" data-testid="backup-restore-compat">{{ gp247_language_render('admin.backup.restore.compat_' . ($compat['status'] ?? 'unknown')) }}</x-gp247::alert>
                @endif
                {{-- Which package / extension each update this site is missing comes from: what to update or install first. --}}
                @if (!empty($compat['missing_by_source']))
                    <div class="space-y-1 text-sm text-gray-700 dark:text-gray-200" data-testid="backup-restore-missing-migrations">
                        <p class="font-medium">{{ gp247_language_render('admin.backup.restore.missing_by_source') }}</p>
                        <ul class="list-disc ps-5 text-xs text-gray-600 dark:text-gray-300">
                            @foreach ((array) $compat['missing_by_source'] as $source => $migrations)
                                <li>{{ $source === 'unknown' ? gp247_language_render('admin.backup.restore.missing_unknown') : $source }}: {{ implode(', ', array_slice((array) $migrations, 0, 10)) }}{{ count((array) $migrations) > 10 ? ' …' : '' }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if (!empty($preview['credential_tables']))
                    <x-gp247::alert type="warning" data-testid="backup-restore-credentials">{{ gp247_language_render('admin.backup.restore.credentials', ['tables' => implode(', ', (array) $preview['credential_tables'])]) }}</x-gp247::alert>
                @endif
                @if (!empty($preview['lost_columns']))
                    <div class="space-y-1 text-sm" data-testid="backup-restore-lost-columns">
                        <x-gp247::alert type="warning">{{ gp247_language_render('admin.backup.restore.lost_columns') }}</x-gp247::alert>
                        <ul class="list-disc ps-5 text-xs text-gray-600 dark:text-gray-300">
                            @foreach ((array) $preview['lost_columns'] as $lostTable => $lostColumns)
                                <li>{{ $lostTable }}: {{ implode(', ', (array) $lostColumns) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                {{-- Mod 20261110T164834 (C3): what this restore does NOT bring back, said before it runs. --}}
                @php($notRestored = array_filter([
                    'absent' => (array) ($preview['absent'] ?? []),
                    'excluded' => (array) ($preview['excluded'] ?? []),
                    'not_in_archive' => (array) ($preview['not_in_archive'] ?? []),
                ]))
                @if ($notRestored !== [] || !empty($preview['file_groups_not_restored']))
                    <div class="space-y-1 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-200" data-testid="backup-restore-not-restored">
                        <p class="font-medium">{{ gp247_language_render('admin.backup.restore.not_restored') }}</p>
                        @foreach ($notRestored as $why => $names)
                            <p class="text-xs" data-testid="backup-restore-not-restored-{{ $why }}">{{ gp247_language_render('admin.backup.restore.not_restored_' . $why) }}: {{ implode(', ', $names) }}</p>
                        @endforeach
                        @if (!empty($preview['file_groups_not_restored']))
                            <p class="text-xs" data-testid="backup-restore-not-restored-files">{{ gp247_language_render('admin.backup.restore.not_restored_files') }}: {{ implode(', ', array_map(fn ($g) => gp247_language_render('admin.backup.files.' . $g), (array) $preview['file_groups_not_restored'])) }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.restore.files_cli_only') }}</p>
                        @endif
                    </div>
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

    {{-- Bring in a .gp247bak file (US-backup-restore-import-archive): sent in small pieces, then
         opened with the password / recovery key typed above — of the site that made it. --}}
    <x-gp247::card :title="gp247_language_render('admin.backup.import.title')" :subtitle="gp247_language_render('admin.backup.import.subtitle', ['max' => (string) intdiv($uploadMaxBytes, 1048576)])" data-testid="backup-restore-upload">
        <div wire:ignore
            x-data="{
                file: null, sent: 0, total: 0, busy: false, error: '',
                max: @js($uploadMaxBytes),
                csrf: @js(csrf_token()),
                async upload() {
                    if (!this.file || this.busy) return;
                    this.error = '';
                    if (this.file.size > this.max) { this.error = 'upload_too_large'; return; }
                    this.busy = true;
                    try {
                        const plan = await $wire.prepareUpload(this.file.size);
                        if (!plan) { this.busy = false; return; }
                        this.total = this.file.size; this.sent = 0;
                        let retries = 0;
                        while (this.sent < this.total) {
                            const piece = this.file.slice(this.sent, Math.min(this.total, this.sent + plan.chunk));
                            const r = await fetch(plan.url, { method: 'POST', credentials: 'same-origin', body: piece, headers: { 'X-CSRF-TOKEN': this.csrf, 'X-Upload-Offset': String(this.sent), 'Content-Type': 'application/octet-stream', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                            const d = await r.json().catch(() => ({}));
                            if (r.status === 409 && typeof d.received === 'number' && retries++ < 3) { this.sent = d.received; continue; }
                            if (!r.ok) { this.error = d.error || 'upload_failed'; break; }
                            this.sent = d.received; retries = 0;
                        }
                        if (this.sent === this.total && !this.error) { await $wire.importUpload(plan.id); }
                    } catch (e) { this.error = 'upload_failed'; }
                    this.busy = false;
                }
            }" class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <p class="text-sm text-gray-600 dark:text-gray-300" style="grid-column: 1 / -1">{{ gp247_language_render('admin.backup.import.help') }}</p>
            <input type="file" accept=".gp247bak" x-on:change="file = $event.target.files[0] || null" class="{{ $inputCls }}" data-testid="backup-restore-upload-file" />
            <div class="flex items-center gap-2">
                <x-gp247::button x-on:click="upload()" x-bind:disabled="!file || busy" :disabled="!$enabled" data-testid="backup-restore-upload-start">{{ gp247_language_render('admin.backup.import.start') }}</x-gp247::button>
                <span class="text-xs text-gray-500 dark:text-gray-400" x-show="busy" x-text="total ? Math.floor(100 * sent / total) + '%' : ''" data-testid="backup-restore-upload-progress"></span>
            </div>
            <p class="text-sm text-red-600" x-show="error" x-text="@js(gp247_language_render('admin.backup.import.upload_failed')) + ' (' + error + ')'" data-testid="backup-restore-upload-error" style="grid-column: 1 / -1"></p>
        </div>
    </x-gp247::card>

    {{-- BK-6: archives at the external destination (US-backup-storage-fetch-remote). Listing needs no
         backup password; bringing one back uses the password / recovery key typed above. --}}
    <x-gp247::card :title="gp247_language_render('admin.backup.remote.title')" :subtitle="gp247_language_render('admin.backup.remote.help')" data-testid="backup-restore-remote">
        @if (!$offsiteConfigured)
            <p class="text-sm text-gray-600 dark:text-gray-300" data-testid="backup-remote-not-configured">{{ gp247_language_render('admin.backup.remote.not_configured') }}</p>
        @else
            <div class="space-y-4">
                <x-gp247::button variant="secondary" wire:click="listRemote" :disabled="!$enabled" data-testid="backup-remote-list">{{ gp247_language_render('admin.backup.remote.list') }}</x-gp247::button>
                @if (is_array($remoteArchives))
                    @if ($remoteArchives === [])
                        <p class="text-sm text-gray-600 dark:text-gray-300" data-testid="backup-remote-empty">{{ gp247_language_render('admin.backup.remote.empty') }}</p>
                    @else
                        <x-gp247::table>
                            @foreach ($remoteArchives as $remote)
                                <tr wire:key="backup-remote-{{ $remote['id'] }}" data-testid="backup-remote-row">
                                    <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $remote['modified_at'] ? \Illuminate\Support\Carbon::createFromTimestamp($remote['modified_at'])->setTimezone(config('app.timezone'))->toDateTimeString() : '—' }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-700 dark:text-gray-200">{{ $remote['id'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format(((int) $remote['bytes']) / 1048576, 1) }} MB</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        @if (!$remote['complete'])
                                            <x-gp247::badge color="amber">{{ gp247_language_render('admin.backup.remote.incomplete') }}</x-gp247::badge>
                                        @elseif ($remote['in_catalogue'])
                                            <x-gp247::badge color="green">{{ gp247_language_render('admin.backup.remote.in_catalogue') }}</x-gp247::badge>
                                        @else
                                            <x-gp247::button size="sm" wire:click="fetchRemote('{{ $remote['id'] }}')" :disabled="!$enabled" data-testid="backup-remote-fetch">{{ gp247_language_render('admin.backup.remote.fetch') }}</x-gp247::button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-gp247::table>
                    @endif
                @endif
            </div>
        @endif
    </x-gp247::card>

    @include('gp247-admin::backup.partials.step-up')
</div>
