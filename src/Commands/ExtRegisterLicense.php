<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use GP247\Core\Library\LicenseRegistrar;

/**
 * Register this site's API-connection license (GP247_API_LICENSE) with the
 * marketplace from the CLI — the command-line parity of the admin "From
 * library → Click here" button.
 *
 * The license is bound to this site's domain (url('/'), which resolves to
 * APP_URL in a console context), so the command echoes the domain and warns
 * when APP_URL is still the default, since registering the wrong domain is the
 * root cause of later "domain_not_authorized" marketplace errors.
 *
 * Unlike gp247:ext-license (per-plugin license stored in admin_config), this
 * writes the domain-scoped API license into .env via the shared
 * LicenseRegistrar service. There is no --type: the license is per-domain, not
 * per-extension.
 *
 * Security (RISK-SEC-cli-license-token-stdout, NFR-SEC-cli-env-license): the
 * raw token is printed ONLY when .env could not be written (so the operator
 * can add it by hand on a read-only shared host). On the normal success path
 * the token is never echoed.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-register-license
 * @aidlc-adr system-cli_service-extraction
 */
class ExtRegisterLicense extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247:ext-register-license';

    /** @var string */
    protected $description = 'Register this domain\'s API-connection license with the marketplace (parity with admin "Click here")';

    /**
     * @return int Command::SUCCESS on a registered+persisted license,
     *   Command::FAILURE on a marketplace error or an unwritable .env.
     */
    protected function handleGp247(): int
    {
        $domain = url('/');
        $this->info('Registering API license for domain: ' . $domain);
        if ($domain === 'http://localhost') {
            // WHY: APP_URL drives the domain sent to the marketplace; the
            // default binds the wrong domain and every later marketplace call
            // fails with domain_not_authorized.
            $this->addWarning('APP_URL is still the default "http://localhost" — set your real domain in .env before registering, otherwise the license will bind the wrong domain.');
        }

        $result = (new LicenseRegistrar)->register();

        if (($result['status'] ?? 'error') !== 'success') {
            return $this->respondFailure('register_failed', $result['message'] ?? 'Registration failed.', ['domain' => $domain]);
        }

        if (!empty($result['wrote_env'])) {
            $this->info('License registered and written to .env.');
            return $this->respondSuccess(['domain' => $domain, 'wrote_env' => true]);
        }

        // .env is not writable (valid on locked-down shared hosts): hand back
        // the token so the operator can paste it. This is the only branch that
        // exposes the token.
        $token = $result['license'] ?? '';
        $message = $result['message'] . ' Add this line to your .env manually (keep it secret — do not commit or share):' . PHP_EOL
            . 'GP247_API_LICENSE=' . $token;

        return $this->respondFailure('env_write_failed', $message, ['domain' => $domain, 'wrote_env' => false]);
    }
}
