<?php

namespace GP247\Core\SecurityHeaders\Livewire;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\AdminShell\Support\AdminAlertService;
use GP247\Core\SecurityHeaders\Application\HeaderPolicyResolver;
use GP247\Core\SecurityHeaders\Application\SecurityHeaderSettings;
use GP247\Core\SecurityHeaders\Domain\HeaderPolicy;
use GP247\Core\SecurityHeaders\Domain\HstsSetting;
use GP247\Core\SecurityHeaders\Domain\InvalidSecuritySetting;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "Security headers" screen: switch each header on or off and set up HSTS. Administrator
 * only — the path is in PermissionMiddleware::administratorOnly() and the component gates
 * mount and save itself. Site-wide (no store scope).
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-settings, US-security-headers-hsts-opt-in, US-security-headers-emergency-off
 * @aidlc-adr security-headers_config-hsts-and-kill-switch
 */
class SecurityHeadersSettingsForm extends Component
{
    public bool $frame = true;
    public bool $nosniff = true;
    public bool $referrer = true;
    public bool $permissions = true;
    public bool $hstsEnabled = false;
    public int $hstsMaxAge = HstsSetting::STEPS[0];
    public bool $hstsSubdomains = false;
    public bool $hstsPreload = false;
    public bool $saved = false;

    /**
     * Load the stored settings.
     *
     * @return void
     */
    public function mount(): void
    {
        $this->authorizeAdministrator();
        $values = SecurityHeaderSettings::values();
        $this->frame = (bool) $values[SecurityHeaderSettings::FRAME];
        $this->nosniff = (bool) $values[SecurityHeaderSettings::NOSNIFF];
        $this->referrer = (bool) $values[SecurityHeaderSettings::REFERRER];
        $this->permissions = (bool) $values[SecurityHeaderSettings::PERMISSIONS];
        $this->hstsEnabled = (bool) $values[SecurityHeaderSettings::HSTS_ENABLED];
        $this->hstsMaxAge = (int) $values[SecurityHeaderSettings::HSTS_MAX_AGE];
        $this->hstsSubdomains = (bool) $values[SecurityHeaderSettings::HSTS_SUBDOMAINS];
        $this->hstsPreload = (bool) $values[SecurityHeaderSettings::HSTS_PRELOAD];
    }

    /**
     * Any edit hides the "saved" badge until the next save.
     *
     * @param string $property
     * @return void
     */
    public function updated(string $property): void
    {
        if ($property !== 'saved') {
            $this->saved = false;
        }
    }

    /**
     * Validate and store the settings; a broken HSTS rule shows under the HSTS block.
     *
     * @return void
     */
    public function save(): void
    {
        $this->authorizeAdministrator();
        $this->resetErrorBag();
        try {
            SecurityHeaderSettings::save([
                SecurityHeaderSettings::FRAME => $this->frame,
                SecurityHeaderSettings::NOSNIFF => $this->nosniff,
                SecurityHeaderSettings::REFERRER => $this->referrer,
                SecurityHeaderSettings::PERMISSIONS => $this->permissions,
                SecurityHeaderSettings::HSTS_ENABLED => $this->hstsEnabled,
                SecurityHeaderSettings::HSTS_MAX_AGE => $this->hstsMaxAge,
                SecurityHeaderSettings::HSTS_SUBDOMAINS => $this->hstsSubdomains,
                SecurityHeaderSettings::HSTS_PRELOAD => $this->hstsPreload,
            ], (string) config('app.url'));
            $this->saved = true;
        } catch (InvalidSecuritySetting $e) {
            $this->saved = false;
            $this->addError('hsts', gp247_language_render($e->getMessage()));
        }
    }

    /**
     * @return View
     */
    public function render(): View
    {
        $this->authorizeAdministrator();

        return view('gp247-admin::livewire.security-headers-settings', [
            'steps' => HstsSetting::STEPS,
            'preloadMin' => HstsSetting::PRELOAD_MIN,
            'siteIsHttps' => strtolower((string) parse_url((string) config('app.url'), PHP_URL_SCHEME)) === 'https',
            'emergencyOff' => !app(HeaderPolicyResolver::class)->enabled(),
            'granted' => array_values(array_intersect(
                HeaderPolicy::FEATURES,
                (array) config('gp247-config.security_headers.permissions_allow', [])
            )),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('admin.security_headers.title'),
        ]);
    }

    /**
     * @return void
     * @throws AuthorizationException When the signed-in admin is not an administrator.
     */
    private function authorizeAdministrator(): void
    {
        if (!app(AdminAlertService::class)->allowed()) {
            throw AuthorizationException::fromReason('security headers are set by administrators only');
        }
    }
}
