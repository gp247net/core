{{--
    "Security headers" screen (US-security-headers-settings, US-security-headers-hsts-opt-in).
    Administrator only. One card with the four headers, one card for HSTS. Only class
    combinations already in the prebuilt admin bundle are used (gp247.md §3a).

    @aidlc-unit security-headers
    @aidlc-story US-security-headers-settings, US-security-headers-hsts-opt-in, US-security-headers-emergency-off
    @aidlc-adr security-headers_config-hsts-and-kill-switch

    Variables: $steps (list<int>), $preloadMin (int), $siteIsHttps (bool), $emergencyOff (bool), $granted (list<string>).
--}}
@php($inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100')
<div data-testid="security-headers-page" class="space-y-6">
    @if ($emergencyOff)
        <x-gp247::alert type="warning" data-testid="security-headers-emergency-off">{{ gp247_language_render('admin.security_headers.emergency_off') }}</x-gp247::alert>
    @endif

    <x-gp247::card :title="gp247_language_render('admin.security_headers.title')" :subtitle="gp247_language_render('admin.security_headers.subtitle')">
        <div class="space-y-4">
            @foreach (['frame', 'nosniff', 'referrer', 'permissions'] as $header)
                <label class="flex items-start gap-3" data-testid="security-headers-row-{{ $header }}">
                    <x-gp247::checkbox wire:model="{{ $header }}" data-testid="security-headers-toggle-{{ $header }}" />
                    <span>
                        <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.security_headers.' . $header) }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.security_headers.' . $header . '_help') }}</span>
                    </span>
                </label>
            @endforeach
            @if ($granted !== [])
                <p class="text-xs text-gray-500 dark:text-gray-400" data-testid="security-headers-granted">
                    {{ gp247_language_render('admin.security_headers.permissions_granted', ['features' => implode(', ', $granted)]) }}
                </p>
            @endif
        </div>
    </x-gp247::card>

    <x-gp247::card :title="gp247_language_render('admin.security_headers.hsts')" :subtitle="gp247_language_render('admin.security_headers.hsts_help')">
        <div class="space-y-4" data-testid="security-headers-hsts">
            @if (!$siteIsHttps)
                <x-gp247::alert type="info" data-testid="security-headers-hsts-needs-https">{{ gp247_language_render('admin.security_headers.error_hsts_needs_https') }}</x-gp247::alert>
            @endif
            <label class="flex items-center gap-3">
                <x-gp247::checkbox wire:model.live="hstsEnabled" data-testid="security-headers-toggle-hsts" />
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.security_headers.hsts_enabled') }}</span>
            </label>
            @if ($hstsEnabled)
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.security_headers.hsts_max_age') }}</label>
                        <select wire:model="hstsMaxAge" class="{{ $inputCls }}" data-testid="security-headers-hsts-max-age">
                            @foreach ($steps as $step)
                                <option value="{{ $step }}">{{ gp247_language_render('admin.security_headers.step_' . $step) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="flex items-center gap-3">
                            <x-gp247::checkbox wire:model="hstsSubdomains" data-testid="security-headers-toggle-hsts-subdomains" />
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.security_headers.hsts_subdomains') }}</span>
                        </label>
                        <label class="flex items-center gap-3">
                            <x-gp247::checkbox wire:model="hstsPreload" data-testid="security-headers-toggle-hsts-preload" />
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ gp247_language_render('admin.security_headers.hsts_preload') }}</span>
                        </label>
                    </div>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.security_headers.hsts_warning') }}</p>
            @endif
            @error('hsts')
                <x-gp247::alert type="error" data-testid="security-headers-hsts-error">{{ $message }}</x-gp247::alert>
            @enderror
        </div>
    </x-gp247::card>

    <div class="flex items-center gap-2">
        <x-gp247::button wire:click="save" data-testid="security-headers-save"
            :wire:confirm="$hstsEnabled ? gp247_language_render('admin.security_headers.hsts_confirm') : null">{{ gp247_language_render('admin.security_headers.save') }}</x-gp247::button>
        @if ($saved)
            <x-gp247::badge color="green" data-testid="security-headers-saved">{{ gp247_language_render('admin.security_headers.saved') }}</x-gp247::badge>
        @endif
    </div>
</div>
