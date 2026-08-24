<?php

namespace GP247\Core\Library;

use Illuminate\Support\Facades\Http;

/**
 * Single client for the GP247 marketplace API (GP247_LIBRARY_API).
 *
 * Consolidates the previously scattered curl / Http calls (extension listing,
 * license-gated download, license registration) behind one place with uniform
 * headers (GP247-API-License / GP247-API-Domain), TLS-verify and timeout policy
 * so both the admin controllers and the CLI (gp247:ext-*) share it
 * (ADR system-cli_service-extraction).
 *
 * Note: batch check-update and per-plugin license validation continue to live in
 * ExtensionUpdateManager, which already encapsulates their caching/entitlement
 * logic; this client covers the remaining direct HTTP surfaces.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_service-extraction
 */
class LibraryClient
{
    /** @var int Timeout (seconds) for listing/registration calls. */
    protected int $timeout = 10;

    /** @var int Timeout (seconds) for downloads. */
    protected int $downloadTimeout = 120;

    /**
     * Base marketplace API URL (no trailing slash).
     *
     * @return string
     */
    protected function baseUrl(): string
    {
        return rtrim((string) config('gp247-config.env.GP247_LIBRARY_API'), '/');
    }

    /**
     * Whether to verify the marketplace TLS certificate.
     *
     * @return bool
     */
    protected function verifySsl(): bool
    {
        return (bool) config('gp247-config.admin.extension.update_verify_ssl', true);
    }

    /**
     * Common request headers (license + calling domain).
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'GP247-API-License' => (string) config('gp247-config.env.GP247_API_LICENSE'),
            'GP247-API-Domain'  => url('/'),
            'Accept'            => 'application/json',
        ];
    }

    /**
     * List/search extensions of a group from the marketplace.
     *
     * @param string               $groupType Plugins|Templates.
     * @param array<string, mixed> $params    Query params (page, keyword, is_free, type_sort...).
     * @return array Decoded API response (['data' => [...], ...]) or ['data' => [], 'error' => msg].
     */
    public function list(string $groupType, array $params = []): array
    {
        $query = array_merge([
            'version' => config('gp247.core'),
        ], $params);

        try {
            $response = Http::withHeaders($this->headers())
                ->withOptions(['verify' => $this->verifySsl()])
                ->timeout($this->timeout)
                ->get($this->baseUrl().'/'.strtolower($groupType), $query);

            // WHY: Http::get() does NOT throw on 4xx/5xx, so without this check a
            // 403/401/5xx body (e.g. {"code":"domain_not_authorized"}) would be
            // returned verbatim — a valid array with no 'data'/'error' key — and
            // callers would read it as an empty-but-successful listing, masking the
            // real failure as "not found" (RISK-TECH-cli-marketplace-error-swallow).
            if (!$response->successful()) {
                // Preserve the API's own error fields (e.g. code/message) so the
                // admin "From library" banner can display them, while guaranteeing
                // the normalized contract the CLI relies on: empty data, a concrete
                // error string, and the HTTP status. `status` is the HTTP status
                // code (int) and intentionally overrides any string "status" the
                // body may carry (RISK-TECH-cli-marketplace-error-swallow).
                $body = $response->json();
                return array_merge(is_array($body) ? $body : [], [
                    'data'   => [],
                    'error'  => $this->errorMessage($response),
                    'status' => $response->status(),
                ]);
            }

            $data = $response->json();
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid marketplace response');
            }
            return $data;
        } catch (\Throwable $e) {
            gp247_report(msg: 'API Error: '.$e->getMessage(), channel: null);
            return ['data' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract a human-readable error message from a non-successful response.
     *
     * Prefers the API's own message fields, then falls back to the HTTP status so
     * the caller always surfaces a concrete cause instead of a generic failure.
     *
     * @param \Illuminate\Http\Client\Response $response The failed HTTP response.
     * @return string A concrete error message (never empty).
     */
    protected function errorMessage($response): string
    {
        $body = $response->json();
        if (is_array($body)) {
            foreach (['message', 'msg', 'error', 'code'] as $field) {
                if (!empty($body[$field]) && is_string($body[$field])) {
                    return $body[$field];
                }
            }
        }
        return 'HTTP '.$response->status();
    }

    /**
     * Download a (paid) extension zip through the license-gated endpoint.
     *
     * Returns the raw body: either zip bytes or a JSON error string, which the
     * caller (ExtensionInstaller) inspects.
     *
     * @param string $groupType Plugins|Templates.
     * @param string $key       Extension key.
     * @param string $license   Per-plugin license.
     * @return string Response body (zip bytes or JSON error).
     */
    public function download(string $groupType, string $key, string $license): string
    {
        $response = Http::withHeaders($this->headers())
            ->withOptions(['verify' => $this->verifySsl()])
            ->timeout($this->downloadTimeout)
            ->get($this->baseUrl().'/extension/download', [
                'type'          => $groupType,
                'key'           => $key,
                'gp247_version' => (string) config('gp247.core'),
                'license'       => $license,
            ]);

        if (!$response->successful()) {
            return json_encode(['error' => 1, 'msg' => 'Download failed: HTTP '.$response->status()]);
        }
        return $response->body();
    }

    /**
     * Register an API-connection license with the marketplace.
     *
     * @param array<string, mixed> $fields POST fields forwarded to the endpoint.
     * @return array{status: string, message: string, data: mixed}
     */
    public function registerLicense(array $fields): array
    {
        try {
            $response = Http::withHeaders(['GP247-API-Domain' => url('/'), 'Accept' => 'application/json'])
                ->withOptions(['verify' => $this->verifySsl()])
                ->timeout($this->timeout)
                ->asForm()
                ->post($this->baseUrl().'/register-license', $fields);

            $data = $response->json();
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid register-license response');
            }
            return [
                'status'  => $data['status'] ?? 'error',
                'message' => $data['message'] ?? 'Unknown error',
                'data'    => $data['data'] ?? null,
            ];
        } catch (\Throwable $e) {
            gp247_report(msg: 'API Register Error: '.$e->getMessage(), channel: null);
            return ['status' => 'error', 'message' => $e->getMessage(), 'data' => null];
        }
    }
}
