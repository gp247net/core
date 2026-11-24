{{--
    GP247 "confirm it is you" box, driven by the shared ConfirmsStepUp trait
    (GP247\Core\Support\StepUp\Concerns\ConfirmsStepUp) of the Livewire screen that includes it:
    the sign-in password, plus the second-step code when the account has one. Renders nothing
    while the trait's $stepUpOpen is false. Same overlay classes as <x-gp247::modal>.

    @aidlc-unit personal-data-registry
    @aidlc-story US-customer-privacy-admin-delete-complete
    @aidlc-adr personal-data-registry_shared-step-up

    @props
      - open (bool): the trait's $stepUpOpen.
      - needsCode (bool): ask for the second-step code too.
      - error (string|null): the trait's $stepUpError.
      - minutes (int): validity shown in the help line.
      - doneEvent (string): browser event the trait sends once confirmed (the box removes itself).
      - testid (string): prefix of the data-testid attributes.
--}}
@props([
    'open' => false,
    'needsCode' => false,
    'error' => null,
    'minutes' => 5,
    'doneEvent' => 'gp247-step-up-done',
    'testid' => 'step-up',
])

@if ($open)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="{{ $testid }}-dialog"
        x-data x-on:{{ $doneEvent }}.window="$el.remove()">
        <div class="absolute inset-0 bg-gray-900/50" wire:click="cancelStepUp"></div>
        <div class="relative w-full max-w-lg rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.step_up.title') }}</h3>
                <button type="button" wire:click="cancelStepUp" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" aria-label="{{ gp247_language_render('admin.step_up.cancel') }}">&times;</button>
            </div>
            <form wire:submit="confirmStepUp" class="space-y-4 p-5">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ gp247_language_render('admin.step_up.help', ['minutes' => $minutes]) }}</p>
                <x-gp247::input type="password" name="stepUpPassword" wire:model="stepUpPassword" autocomplete="current-password"
                    :label="gp247_language_render('admin.step_up.password')" data-testid="{{ $testid }}-password" />
                @if ($needsCode)
                    <x-gp247::input type="text" name="stepUpSecretCode" wire:model="stepUpSecretCode" autocomplete="one-time-code" inputmode="numeric"
                        :label="gp247_language_render('admin.step_up.code')" data-testid="{{ $testid }}-code" />
                @endif
                @if ($error)
                    <p class="text-sm text-red-600" data-testid="{{ $testid }}-error">{{ $error }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <x-gp247::button type="button" variant="secondary" wire:click="cancelStepUp" data-testid="{{ $testid }}-cancel">{{ gp247_language_render('admin.step_up.cancel') }}</x-gp247::button>
                    <x-gp247::button type="submit" data-testid="{{ $testid }}-confirm">{{ gp247_language_render('admin.step_up.confirm') }}</x-gp247::button>
                </div>
            </form>
        </div>
    </div>
@endif
