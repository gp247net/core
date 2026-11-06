{{--
    Backup screen (US-backup-admin-dashboard): first-time set-up, protection status and warnings,
    create buttons, archive list with the actions each right allows. Buttons follow the rights
    ($can); the server checks every action again. Only class combinations of the prebuilt admin
    bundle (gp247.md §3a), logical properties for RTL.

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-dashboard, US-backup-admin-permissions, US-backup-engine-key-setup
    @aidlc-adr backup-admin_step-up-and-signed-download

    Variables: $enabled, $setUp (bool), $status (DashboardStatus::summary()), $archives (paginator|null),
    $groups (key => DataGroup), $fileGroups (list), $can (create/download/restore/settings), $staleHours,
    $stepUpNeedsCode, $tickUrl, $postIncident (open checklist|null — BK-5).
--}}
@php($inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100')
@php($job = $status['job'])
<div class="space-y-6" data-testid="backup-dashboard-page">
    @if ($notice)
        <x-gp247::alert :type="$noticeType" data-testid="backup-dashboard-notice">{{ $notice }}</x-gp247::alert>
    @endif

    {{-- Tasks a post-incident restore left to the site owner (US-backup-restore-post-incident-mode) --}}
    @if ($postIncident)
        <x-gp247::alert type="warning" data-testid="backup-post-incident">
            <p class="font-medium">{{ gp247_language_render('admin.backup.post_incident.pending_title') }}</p>
            <ul class="mt-2 list-disc list-inside space-y-1 text-sm">
                @foreach (\GP247\Core\Backup\Application\PostIncidentChecklist::TASKS as $task)
                    <li data-testid="backup-post-incident-task-{{ $task }}">{{ gp247_language_render('admin.backup.post_incident.task_' . $task, \GP247\Core\Backup\Application\PostIncidentChecklist::ROTATE_VARIABLES + [
                        'command' => \GP247\Core\Backup\Application\PostIncidentChecklist::ROTATE_COMMAND,
                        'doctor' => \GP247\Core\Backup\Application\PostIncidentChecklist::DOCTOR_COMMAND,
                    ]) }}</li>
                @endforeach
            </ul>
            @if ($can['settings'])
                <div class="mt-2"><x-gp247::button size="sm" variant="secondary" wire:click="markPostIncidentDone" data-testid="backup-post-incident-done">{{ gp247_language_render('admin.backup.post_incident.mark_done') }}</x-gp247::button></div>
            @endif
        </x-gp247::alert>
    @endif

    @if (!$enabled)
        <x-gp247::alert type="warning" data-testid="backup-dashboard-disabled">
            {{ gp247_language_render('admin.backup.warning.disabled') }}
            @if ($can['settings'])
                <a href="{{ gp247_route_admin('admin.backup.settings') }}" class="font-medium underline">{{ gp247_language_render('admin.backup.settings.title') }}</a>
            @endif
        </x-gp247::alert>
    @endif

    {{-- First-time set-up: backup password, then the recovery key shown once --}}
    @if (!$setUp || $shownRecoverySecret !== null)
        <x-gp247::card :title="gp247_language_render('admin.backup.setup.title')" data-testid="backup-setup">
            @if (!$can['settings'])
                <p class="text-sm text-gray-600 dark:text-gray-300" data-testid="backup-setup-ask-admin">{{ gp247_language_render('admin.backup.setup.ask_administrator') }}</p>
            @elseif ($shownRecoverySecret === null)
                <form wire:submit="setupKey" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <p class="text-sm text-gray-600 dark:text-gray-300" style="grid-column: 1 / -1">{{ gp247_language_render('admin.backup.setup.intro', ['min' => \GP247\Core\Backup\Application\KeyService::MIN_PASSWORD_LENGTH]) }}</p>
                    <x-gp247::input type="password" name="setupPassword" wire:model="setupPassword" autocomplete="new-password"
                        :label="gp247_language_render('admin.backup.setup.password')" :error="$errors->first('setupPassword')" data-testid="backup-setup-password" />
                    <x-gp247::input type="password" name="setupPasswordConfirm" wire:model="setupPasswordConfirm" autocomplete="new-password"
                        :label="gp247_language_render('admin.backup.setup.password_again')" :error="$errors->first('setupPasswordConfirm')" data-testid="backup-setup-password-again" />
                    <div style="grid-column: 1 / -1">
                        <x-gp247::button type="submit" data-testid="backup-setup-submit">{{ gp247_language_render('admin.backup.setup.submit') }}</x-gp247::button>
                    </div>
                </form>
            @else
                <div class="space-y-4" data-testid="backup-setup-recovery">
                    <x-gp247::alert type="warning">{{ gp247_language_render('admin.backup.setup.recovery_once') }}</x-gp247::alert>
                    <p class="break-all rounded-lg border border-gray-200 p-4 font-mono text-lg tracking-wide text-gray-800 dark:border-gray-700 dark:text-gray-100" data-testid="backup-setup-recovery-key">{{ $shownRecoverySecret }}</p>
                    <div x-data="{ text: @js(gp247_language_render('admin.backup.setup.recovery_file_header') . PHP_EOL . $shownRecoverySecret . PHP_EOL), save() { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([this.text], { type: 'text/plain' })); a.download = 'gp247-backup-recovery-key.txt'; a.click(); URL.revokeObjectURL(a.href); } }">
                        <x-gp247::button variant="secondary" size="sm" x-on:click="save()" data-testid="backup-setup-recovery-download">
                            {{ gp247_language_render('admin.backup.setup.recovery_download') }}
                        </x-gp247::button>
                    </div>
                    <form wire:submit="confirmRecoveryKey" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-gp247::input type="text" name="recoverySecretCheck" wire:model="recoverySecretCheck" autocomplete="off"
                            :label="gp247_language_render('admin.backup.setup.recovery_check')" :error="$errors->first('recoverySecretCheck')" data-testid="backup-setup-recovery-check" />
                        <div class="flex items-end">
                            <x-gp247::button type="submit" data-testid="backup-setup-recovery-confirm">{{ gp247_language_render('admin.backup.setup.recovery_confirm') }}</x-gp247::button>
                        </div>
                    </form>
                </div>
            @endif
        </x-gp247::card>
    @endif

    @if ($setUp)
        {{-- Protection status --}}
        <x-gp247::card :title="gp247_language_render('admin.backup.title')" :subtitle="gp247_language_render('admin.backup.subtitle')" data-testid="backup-status">
            <x-slot:actions>
                @if ($can['restore'])
                    <x-gp247::button variant="secondary" size="sm" class="whitespace-nowrap" :href="gp247_route_admin('admin.backup.restore')" data-testid="backup-open-restore">{{ gp247_language_render('admin.backup.restore.title') }}</x-gp247::button>
                @endif
                @if ($can['settings'])
                    <x-gp247::button variant="secondary" size="sm" class="whitespace-nowrap" :href="gp247_route_admin('admin.backup.settings')" data-testid="backup-open-settings">{{ gp247_language_render('admin.backup.settings.short') }}</x-gp247::button>
                @endif
            </x-slot:actions>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.status.last_ok') }}</p>
                    <p class="text-sm text-gray-800 dark:text-gray-100" data-testid="backup-status-last-ok">
                        @if ($status['last_ok'])
                            {{ $status['last_ok']->created_at }} · {{ gp247_language_render('admin.backup.status.age', ['hours' => $status['age_hours']]) }}
                        @else
                            {{ gp247_language_render('admin.backup.status.none') }}
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.status.rpo_note') }}</p>
                </div>
                <div class="space-y-1">
                    @foreach ($status['warnings'] as $warning)
                        <x-gp247::alert type="warning" data-testid="backup-warning-{{ $warning }}">{{ gp247_language_render('admin.backup.warning.' . $warning, ['hours' => $staleHours]) }}</x-gp247::alert>
                    @endforeach
                </div>
            </div>
            @if ($job)
                <div class="mt-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700" data-testid="backup-job">
                    <p class="text-sm text-gray-700 dark:text-gray-200">
                        {{ gp247_language_render('admin.backup.job.' . $job['type']) }}
                        @if ($job['percent'] !== null) · {{ $job['percent'] }}% @endif
                        @if ($job['phase'] !== '') · {{ $job['phase'] }} @endif
                    </p>
                    @if ($can['create'] && $job['type'] === 'backup' && $job['archive_id'])
                        <x-gp247::button variant="danger" size="sm" class="mt-2" wire:click="cancelBackup('{{ $job['archive_id'] }}')" data-testid="backup-job-cancel">{{ gp247_language_render('admin.backup.job.cancel') }}</x-gp247::button>
                    @endif
                </div>
            @endif
            @include('gp247-admin::backup.partials.runner', ['tickUrl' => $tickUrl, 'active' => $can['create'] && $job && $job['type'] !== 'restore'])
        </x-gp247::card>

        {{-- Create --}}
        @if ($can['create'] && $enabled)
            <x-gp247::card :title="gp247_language_render('admin.backup.create.title')" data-testid="backup-create">
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.backup.create.snapshot') }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.create.snapshot_help') }}</p>
                        <select wire:model="snapshotGroup" class="{{ $inputCls }}" data-testid="backup-create-snapshot-group">
                            @foreach ($groups as $key => $group)
                                <option value="{{ $key }}">{{ gp247_language_render($group->label) === $group->label ? \Illuminate\Support\Str::headline($key) : gp247_language_render($group->label) }}</option>
                            @endforeach
                        </select>
                        <x-gp247::button size="sm" wire:click="createSnapshot" data-testid="backup-create-snapshot">{{ gp247_language_render('admin.backup.create.start') }}</x-gp247::button>
                    </div>
                    <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.backup.create.selective') }}</p>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach ($groups as $key => $group)
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                    <x-gp247::checkbox wire:model="selectiveGroups" value="{{ $key }}" data-testid="backup-create-selective-group-{{ $key }}" />
                                    {{ gp247_language_render($group->label) === $group->label ? \Illuminate\Support\Str::headline($key) : gp247_language_render($group->label) }}
                                </label>
                            @endforeach
                            @foreach ($fileGroups as $fileGroup)
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                    <x-gp247::checkbox wire:model="selectiveFiles" value="{{ $fileGroup }}" data-testid="backup-create-selective-files-{{ $fileGroup }}" />
                                    {{ gp247_language_render('admin.backup.files.' . $fileGroup) }}
                                </label>
                            @endforeach
                        </div>
                        <x-gp247::button size="sm" wire:click="createSelective" data-testid="backup-create-selective">{{ gp247_language_render('admin.backup.create.start') }}</x-gp247::button>
                    </div>
                    <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.backup.create.full') }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.create.full_help') }}</p>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <x-gp247::checkbox wire:model="excludeLogs" data-testid="backup-create-full-exclude-logs" />
                            {{ gp247_language_render('admin.backup.create.exclude_logs') }}
                        </label>
                        <div><x-gp247::button size="sm" wire:click="createFull" data-testid="backup-create-full">{{ gp247_language_render('admin.backup.create.start') }}</x-gp247::button></div>
                    </div>
                </div>
                @if ($confirmKind)
                    <x-gp247::alert type="warning" class="mt-4" data-testid="backup-create-confirm-large">
                        {{ gp247_language_render('admin.backup.create.large') }}
                        <div class="mt-2 flex gap-2">
                            <x-gp247::button size="sm" wire:click="confirmLarge" data-testid="backup-create-confirm-large-yes">{{ gp247_language_render('admin.backup.create.large_yes') }}</x-gp247::button>
                            <x-gp247::button size="sm" variant="secondary" wire:click="$set('confirmKind', null)">{{ gp247_language_render('admin.backup.cancel') }}</x-gp247::button>
                        </div>
                    </x-gp247::alert>
                @endif
            </x-gp247::card>
        @endif

        {{-- Archive list --}}
        <x-gp247::card :title="gp247_language_render('admin.backup.list.title')" data-testid="backup-archives">
            <x-gp247::table :empty="$archives->isEmpty() ? gp247_language_render('admin.backup.list.empty') : null">
                <x-slot:head>
                    <tr>
                        @foreach (['created', 'kind', 'scope', 'size', 'where', 'check', 'actions'] as $column)
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.list.' . $column) }}</th>
                        @endforeach
                    </tr>
                </x-slot:head>
                @foreach ($archives as $archive)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" wire:key="backup-archive-{{ $archive->id }}" data-testid="backup-archive-row">
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                            {{ $archive->created_at }}
                            @if ($archive->pinned)
                                <x-gp247::badge color="blue" data-testid="backup-archive-pinned">{{ gp247_language_render('admin.backup.list.pinned') }}</x-gp247::badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                            {{ gp247_language_render('admin.backup.kind.' . $archive->kind) }}
                            <span class="block text-xs text-gray-400">{{ gp247_language_render('admin.backup.reason.' . explode(':', (string) $archive->reason)[0]) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">{{ implode(', ', array_merge((array) ((json_decode((string) $archive->scope, true) ?: [])['groups'] ?? []), (array) ((json_decode((string) $archive->scope, true) ?: [])['files'] ?? []))) ?: ($archive->kind === 'full' ? gp247_language_render('admin.backup.list.scope_all') : '—') }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format(((int) $archive->size) / 1048576, 1) }} MB</td>
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300" data-testid="backup-archive-where">{{ gp247_language_render('admin.backup.where.' . match ($archive->offsite) { 'done' => 'offsite', 'pending' => 'pending', 'failed' => 'failed', default => 'local' }) }}</td>
                        <td class="px-4 py-3">
                            <x-gp247::badge :color="match ($archive->status) { 'ok' => 'green', 'broken' => 'red', 'cancelled' => 'gray', default => 'amber' }" data-testid="backup-archive-status">{{ gp247_language_render('admin.backup.status.' . $archive->status) }}</x-gp247::badge>
                            @if ($archive->consistency)
                                <span class="block text-xs text-gray-400">{{ gp247_language_render('admin.backup.consistency.' . $archive->consistency) }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($archive->status === 'ok' && $can['download'])
                                    <x-gp247::button size="sm" variant="secondary" wire:click="requestDownload('{{ $archive->id }}')" data-testid="backup-archive-download">{{ gp247_language_render('admin.backup.list.download') }}</x-gp247::button>
                                @endif
                                @if ($archive->status === 'ok' && $can['restore'])
                                    <x-gp247::button size="sm" variant="secondary" :href="gp247_route_admin('admin.backup.restore', ['archive' => $archive->id])" data-testid="backup-archive-restore">{{ gp247_language_render('admin.backup.list.restore') }}</x-gp247::button>
                                @endif
                                @if ($can['create'] && $can['offsite'] && $archive->status === 'ok' && in_array($archive->offsite, ['none', 'failed'], true))
                                    <x-gp247::button size="sm" variant="secondary" wire:click="pushOffsite('{{ $archive->id }}')" data-testid="backup-archive-push">{{ gp247_language_render('admin.backup.offsite.push') }}</x-gp247::button>
                                @endif
                                @if ($can['create'] && in_array($archive->status, ['ok', 'broken'], true))
                                    <x-gp247::button size="sm" variant="ghost" wire:click="openVerify('{{ $archive->id }}')" data-testid="backup-archive-verify">{{ gp247_language_render('admin.backup.list.verify') }}</x-gp247::button>
                                    <x-gp247::button size="sm" variant="ghost" wire:click="togglePin('{{ $archive->id }}')" data-testid="backup-archive-pin">{{ gp247_language_render($archive->pinned ? 'admin.backup.list.unpin' : 'admin.backup.list.pin') }}</x-gp247::button>
                                    <x-gp247::button size="sm" variant="ghost" wire:click="openDelete('{{ $archive->id }}')" data-testid="backup-archive-delete">{{ gp247_language_render('admin.backup.list.delete') }}</x-gp247::button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-gp247::table>
            <div class="mt-4">{{ $archives->links('gp247-admin::partials.pagination') }}</div>
        </x-gp247::card>

        {{-- Check again --}}
        @if ($verifyArchiveId)
            <x-gp247::card :title="gp247_language_render('admin.backup.verify.title')" data-testid="backup-verify">
                <form wire:submit="runVerify" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-gp247::input type="password" name="verifySecret" wire:model="verifySecret" autocomplete="off"
                        :label="gp247_language_render($verifyWithRecovery ? 'admin.backup.secret.recovery' : 'admin.backup.secret.password')" data-testid="backup-verify-secret" />
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <x-gp247::checkbox wire:model.live="verifyWithRecovery" data-testid="backup-verify-use-recovery" />
                        {{ gp247_language_render('admin.backup.secret.use_recovery') }}
                    </label>
                    <div class="flex gap-2" style="grid-column: 1 / -1">
                        <x-gp247::button type="submit" data-testid="backup-verify-submit">{{ gp247_language_render('admin.backup.verify.submit') }}</x-gp247::button>
                        <x-gp247::button variant="secondary" wire:click="$set('verifyArchiveId', null)">{{ gp247_language_render('admin.backup.cancel') }}</x-gp247::button>
                    </div>
                </form>
            </x-gp247::card>
        @endif

        {{-- Delete --}}
        @if ($deleteArchiveId)
            <x-gp247::card :title="gp247_language_render('admin.backup.delete.title')" data-testid="backup-delete">
                <form wire:submit="runDelete" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <p class="text-sm text-gray-600 dark:text-gray-300" style="grid-column: 1 / -1">{{ gp247_language_render('admin.backup.delete.help', ['code' => substr($deleteArchiveId, -6)]) }}</p>
                    <x-gp247::input type="text" name="deleteConfirm" wire:model="deleteConfirm" autocomplete="off"
                        :label="gp247_language_render('admin.backup.delete.confirm')" :error="$errors->first('deleteConfirm')" data-testid="backup-delete-confirm" />
                    <div class="flex items-end gap-2">
                        <x-gp247::button type="submit" variant="danger" data-testid="backup-delete-submit">{{ gp247_language_render('admin.backup.list.delete') }}</x-gp247::button>
                        <x-gp247::button variant="secondary" wire:click="$set('deleteArchiveId', null)">{{ gp247_language_render('admin.backup.cancel') }}</x-gp247::button>
                    </div>
                </form>
            </x-gp247::card>
        @endif
    @endif

    @include('gp247-admin::backup.partials.step-up')
</div>
