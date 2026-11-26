{{--
    Backup settings (Administrator only): switch, archives to keep, storage folder; backup password
    change and new recovery key after a re-confirmation (the new key is shown once).

    @aidlc-unit backup-admin
    @aidlc-story US-backup-engine-key-setup, US-backup-admin-step-up-auth
    @aidlc-adr backup-admin_step-up-and-signed-download

    Layout: a menu of the sections on the left (each with a short line of what is saved, amber when
    it needs a look) and the chosen section on the right; the choice is kept in the address (#key).

    Variables: $setUp (bool), $stepUpNeedsCode (bool), $nav (list<array{key: string, title: string,
    summary: string, tone: string}>), $noticeSection ('general'|'security'|null — the section the
    current notice belongs to).
--}}
<div class="gp247-bk-page space-y-4" data-testid="backup-settings-page"
    x-data="{ tab: 'general', keys: @js(array_column($nav, 'key')),
        pick(key) { this.tab = key; history.replaceState(null, '', '#' + key); },
        fromHash() { const key = location.hash.slice(1); if (this.keys.includes(key)) this.tab = key; } }"
    x-init="fromHash()" x-on:hashchange.window="fromHash()">
    {{-- WHY a style block: the two-column split needs a breakpoint width the prebuilt bundle has no class for. --}}
    <style>
        .gp247-bk-page { max-width: 64rem; }
        .gp247-bk-settings { display: grid; grid-template-columns: minmax(0, 1fr); }
        .gp247-bk-settings > nav { border-bottom-width: 1px; border-bottom-style: solid; }
        @media (min-width: 768px) {
            .gp247-bk-settings { grid-template-columns: 15rem minmax(0, 1fr); }
            .gp247-bk-settings > nav { border-bottom-width: 0; border-right-width: 1px; border-right-style: solid; }
        }
    </style>
    @if ($notice && $noticeSection === null)
        <x-gp247::alert :type="$noticeType" data-testid="backup-settings-notice">{{ $notice }}</x-gp247::alert>
    @endif

    <div class="gp247-bk-settings overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <nav class="space-y-1 border-gray-200 p-3 dark:border-gray-700" data-testid="backup-settings-nav">
            @foreach ($nav as $item)
                <button type="button" x-on:click="pick(@js($item['key']))" data-testid="backup-settings-nav-{{ $item['key'] }}"
                    class="block w-full rounded-lg px-3 py-2 text-left"
                    x-bind:class="tab === @js($item['key']) ? 'bg-blue-50 dark:bg-blue-900/20' : 'hover:bg-gray-50 dark:hover:bg-gray-700/50'"
                    x-bind:aria-current="tab === @js($item['key']) ? 'true' : 'false'">
                    <span class="flex items-center justify-between gap-2 text-sm font-medium"
                        x-bind:class="tab === @js($item['key']) ? 'text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-200'">
                        {!! $item['title'] !!}
                        @if ($item['tone'] === 'warning')<span class="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span>@endif
                    </span>
                    <span @class(['mt-0.5 block text-xs', 'text-amber-700' => $item['tone'] === 'warning', 'text-gray-500 dark:text-gray-400' => $item['tone'] !== 'warning'])
                        data-testid="backup-settings-nav-{{ $item['key'] }}-summary">{{ $item['summary'] }}</span>
                </button>
            @endforeach
            <div class="border-t border-gray-200 px-3 pt-3 dark:border-gray-700">
                <a href="{{ gp247_route_admin('admin.backup.archives') }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">← {{ gp247_language_render('admin.backup.title') }}</a>
            </div>
        </nav>

        <div class="p-5">
    {{-- The chosen section (US-backup-admin-dashboard); the others stay in the page, hidden. --}}
    @component('gp247-admin::backup.partials.settings-section', [
        'key' => 'general', 'testid' => 'backup-settings-general', 'title' => gp247_language_render('admin.backup.settings.general'),
        'description' => gp247_language_render('admin.backup.settings.desc.general'),
        'notice' => $noticeSection === 'general' ? $notice : null, 'noticeType' => $noticeType, 'noticeTestid' => 'backup-settings-notice', 'note' => null,
    ])
        <form wire:submit="saveGeneral" class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div style="grid-column: 1 / -1">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <x-gp247::checkbox wire:model="enabled" data-testid="backup-settings-enabled" />
                    {{ gp247_language_render('admin.backup.enabled') }}
                </label>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.backup.settings.disable_keeps') }}</p>
            </div>
            <x-gp247::input type="number" name="keepCount" wire:model="keepCount" min="1" max="100"
                :label="gp247_language_render('admin.backup.settings.keep')" :error="$errors->first('keepCount')" data-testid="backup-settings-keep" />
            <x-gp247::input type="number" name="keepPreChange" wire:model="keepPreChange" min="1" max="20"
                :label="gp247_language_render('admin.backup.settings.keep_pre_change')" :error="$errors->first('keepPreChange')" data-testid="backup-settings-keep-pre-change" />
            <x-gp247::input type="text" name="vaultPath" wire:model="vaultPath"
                :label="gp247_language_render('admin.backup.settings.vault')" :help="gp247_language_render('admin.backup.settings.vault_help')"
                :error="$errors->first('vaultPath')" data-testid="backup-settings-vault" />
            <div style="grid-column: 1 / -1">
                <x-gp247::button type="submit" data-testid="backup-settings-save">{{ gp247_language_render('admin.backup.settings.save') }}</x-gp247::button>
            </div>
        </form>
    @endcomponent

    {{-- BK-7: when scheduled backups run and what they hold, its own component. --}}
    <livewire:gp247-backup::backup-schedule-settings />

    {{-- BK-6: one external destination (S3-compatible / SFTP), its own component. --}}
    <livewire:gp247-backup::backup-destination-settings />

    @if ($setUp)
        @component('gp247-admin::backup.partials.settings-section', [
            'key' => 'security', 'testid' => 'backup-settings-security', 'title' => gp247_language_render('admin.backup.settings.security'),
            'description' => gp247_language_render('admin.backup.settings.desc.security'),
            'notice' => $noticeSection === 'security' ? $notice : null, 'noticeType' => $noticeType, 'noticeTestid' => 'backup-settings-security-notice',
            'note' => gp247_language_render('admin.backup.secret.explain_lost_both'),
        ])
            @if ($shownRecoverySecret !== null)
                <div class="space-y-4" data-testid="backup-settings-recovery">
                    <x-gp247::alert type="warning">{{ gp247_language_render('admin.backup.setup.recovery_once') }}</x-gp247::alert>
                    <p class="break-all rounded-lg border border-gray-200 p-4 font-mono text-lg tracking-wide text-gray-800 dark:border-gray-700 dark:text-gray-100" data-testid="backup-settings-recovery-key">{{ $shownRecoverySecret }}</p>
                    <x-gp247::button wire:click="closeRecoveryKey" data-testid="backup-settings-recovery-close">{{ gp247_language_render('admin.backup.settings.recovery_saved') }}</x-gp247::button>
                </div>
            @else
                <form wire:submit="changePassword" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <details class="text-sm text-gray-600 dark:text-gray-300" style="grid-column: 1 / -1" data-testid="backup-settings-explain">
                        <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.backup.settings.explain_toggle') }}</summary>
                        <div class="mt-3 grid grid-cols-1">
                            @include('gp247-admin::backup.partials.secret-explainer')
                        </div>
                    </details>
                    <x-gp247::input type="password" name="currentPassword" wire:model="currentPassword" autocomplete="off"
                        :label="gp247_language_render($useRecoveryForChange ? 'admin.backup.secret.recovery' : 'admin.backup.settings.current')"
                        :error="$errors->first('currentPassword')" data-testid="backup-settings-current" />
                    <div class="flex items-end">
                        <label class="inline-flex items-center gap-2 py-2 text-sm text-gray-700 dark:text-gray-200">
                            <x-gp247::checkbox wire:model.live="useRecoveryForChange" data-testid="backup-settings-use-recovery" />
                            {{ gp247_language_render('admin.backup.secret.use_recovery') }}
                        </label>
                    </div>
                    <x-gp247::input type="password" name="newPassword" wire:model="newPassword" autocomplete="new-password"
                        :label="gp247_language_render('admin.backup.settings.new')" :error="$errors->first('newPassword')" data-testid="backup-settings-new" />
                    <x-gp247::input type="password" name="newPasswordConfirm" wire:model="newPasswordConfirm" autocomplete="new-password"
                        :label="gp247_language_render('admin.backup.setup.password_again')" :error="$errors->first('newPasswordConfirm')" data-testid="backup-settings-new-again" />
                    <div class="flex flex-wrap gap-2" style="grid-column: 1 / -1">
                        <x-gp247::button type="submit" data-testid="backup-settings-change-password">{{ gp247_language_render('admin.backup.settings.change') }}</x-gp247::button>
                        <x-gp247::button variant="secondary" wire:click="newRecoveryKey" data-testid="backup-settings-new-recovery">{{ gp247_language_render('admin.backup.settings.new_recovery') }}</x-gp247::button>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400" style="grid-column: 1 / -1">{{ gp247_language_render('admin.backup.settings.after_change') }}</p>
                </form>
            @endif
        @endcomponent
    @endif
        </div>
    </div>

    @include('gp247-admin::backup.partials.step-up')
</div>
