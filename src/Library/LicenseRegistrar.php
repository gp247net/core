<?php

namespace GP247\Core\Library;

/**
 * Register the API-connection license (GP247_API_LICENSE) with the marketplace
 * and persist it to the project .env, for a single code-path shared by the
 * Admin UI ("Click here" button) and the CLI (gp247:ext-register-license).
 *
 * The license authorizes this domain to talk to the marketplace and is bound
 * to the domain sent in the GP247-API-Domain header (LibraryClient uses
 * url('/'), which resolves to APP_URL in a console context).
 *
 * Security (RISK-SEC-cli-license-token-stdout, NFR-SEC-cli-env-license): the
 * returned array only carries the raw 'license' token when the .env write
 * FAILED (so the operator can paste it manually on a read-only shared host).
 * When the write succeeds the token is deliberately omitted so no caller can
 * leak it to stdout/logs.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-register-license
 * @aidlc-adr system-cli_service-extraction
 */
class LicenseRegistrar
{
    /**
     * Register this domain's API-connection license and persist it to .env.
     *
     * @return array{status: string, message: string, wrote_env: bool, license?: string}
     *   status: 'success'|'error'. On success, wrote_env indicates whether the
     *   token reached .env; 'license' is present ONLY when success && !wrote_env.
     */
    public function register(): array
    {
        $response = (new LibraryClient)->registerLicense([]);

        if (($response['status'] ?? 'error') !== 'success') {
            return [
                'status'    => 'error',
                'message'   => $response['message'] ?? 'Unknown error',
                'wrote_env' => false,
            ];
        }

        $license = $response['data']['license'] ?? '';
        if ($license === '') {
            return [
                'status'    => 'error',
                'message'   => 'The marketplace returned an empty license.',
                'wrote_env' => false,
            ];
        }

        if ($this->persistToEnv($license)) {
            // WHY: token omitted on the success path so it can never be echoed.
            return [
                'status'    => 'success',
                'message'   => 'License registered successfully.',
                'wrote_env' => true,
            ];
        }

        // .env not writable (valid on locked-down shared hosts): hand the token
        // back so the operator can add it manually. This is the ONLY branch
        // that exposes the raw token.
        return [
            'status'    => 'success',
            'message'   => 'License obtained but .env is not writable.',
            'wrote_env' => false,
            'license'   => $license,
        ];
    }

    /**
     * Write (or replace) GP247_API_LICENSE in the project .env file.
     *
     * @param string $license The license token to persist.
     * @return bool True when the token was written; false when .env is missing
     *   or not writable (never throws — a read-only .env is a valid state).
     */
    public function persistToEnv(string $license): bool
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath) || !is_writable($envPath)) {
            return false;
        }

        $envContent = file_get_contents($envPath);
        if ($envContent === false) {
            return false;
        }

        if (strpos($envContent, 'GP247_API_LICENSE') === false) {
            if ($envContent !== '' && substr($envContent, -1) !== "\n") {
                $envContent .= "\n";
            }
            $envContent .= 'GP247_API_LICENSE=' . $license . "\n";
        } else {
            $envContent = preg_replace(
                '/GP247_API_LICENSE=.*/',
                'GP247_API_LICENSE=' . $license,
                $envContent
            );
        }

        try {
            return file_put_contents($envPath, $envContent) !== false;
        } catch (\Throwable $e) {
            gp247_report(msg: 'License .env write error: ' . $e->getMessage(), channel: null);
            return false;
        }
    }
}
