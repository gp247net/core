{{--
    "Confirm it is you" box of the backup screens (US-backup-admin-step-up-auth), driven by the
    ConfirmsStepUp trait ($stepUpOpen). Sign-in password, plus the second-step code when the site
    has one ($stepUpNeedsCode). Same overlay classes as <x-gp247::modal> (prebuilt bundle).

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-step-up-auth
--}}
@if ($stepUpOpen)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="backup-step-up-dialog">
        <div class="absolute inset-0 bg-gray-900/50" wire:click="cancelStepUp"></div>
        <div class="relative w-full max-w-lg rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.backup.step_up.title') }}</h3>
                <button type="button" wire:click="cancelStepUp" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" aria-label="{{ gp247_language_render('admin.close') }}">&times;</button>
            </div>
            <form wire:submit="confirmStepUp" class="space-y-4 p-5">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ gp247_language_render('admin.backup.step_up.help', ['minutes' => intdiv(\GP247\Core\Backup\Application\StepUpService::seconds(), 60)]) }}</p>
                <x-gp247::input type="password" name="stepUpPassword" wire:model="stepUpPassword" autocomplete="current-password"
                    :label="gp247_language_render('admin.backup.step_up.password')" data-testid="backup-step-up-password" />
                @if ($stepUpNeedsCode)
                    <x-gp247::input type="text" name="stepUpSecretCode" wire:model="stepUpSecretCode" autocomplete="one-time-code" inputmode="numeric"
                        :label="gp247_language_render('admin.backup.step_up.code')" data-testid="backup-step-up-code" />
                @endif
                @if ($stepUpError)
                    <p class="text-sm text-red-600" data-testid="backup-step-up-error">{{ $stepUpError }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <x-gp247::button type="button" variant="secondary" wire:click="cancelStepUp" data-testid="backup-step-up-cancel">{{ gp247_language_render('admin.backup.cancel') }}</x-gp247::button>
                    <x-gp247::button type="submit" data-testid="backup-step-up-confirm">{{ gp247_language_render('admin.backup.step_up.confirm') }}</x-gp247::button>
                </div>
            </form>
        </div>
    </div>
@endif
