<?php

namespace GP247\Core\Tests\Feature\SystemCli;

use GP247\Core\Library\LicenseRegistrar;
use Illuminate\Support\Facades\Http;
use GP247\Core\Tests\TestCase;

/**
 * Verifies the shared LicenseRegistrar service used by both the admin
 * "From library → Click here" button and the gp247:ext-register-license CLI
 * command (US-CLI-register-license).
 *
 * The security-critical invariant (RISK-SEC-cli-license-token-stdout,
 * NFR-SEC-cli-env-license): the raw token is returned ONLY when the .env write
 * failed; on the success-write path it is omitted so no caller can leak it.
 * persistToEnv() is overridden per-test so the real project .env is never
 * touched and the writable/read-only outcomes are deterministic.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-register-license
 * @aidlc-adr system-cli_service-extraction
 */
class LicenseRegistrarTest extends TestCase
{
    /**
     * Build a LicenseRegistrar whose persistToEnv() is stubbed to a fixed
     * outcome, so register() can be tested without touching the real .env.
     *
     * @param bool $wrote Whether the simulated .env write succeeds.
     * @return LicenseRegistrar
     */
    protected function registrarWithEnvWrite(bool $wrote): LicenseRegistrar
    {
        return new class($wrote) extends LicenseRegistrar {
            /** @var bool */
            private $wrote;

            /**
             * @param bool $wrote Simulated persistToEnv() result.
             */
            public function __construct(bool $wrote)
            {
                $this->wrote = $wrote;
            }

            /**
             * @param string $license Ignored — no real file is written.
             * @return bool The pre-seeded outcome.
             */
            public function persistToEnv(string $license): bool
            {
                return $this->wrote;
            }
        };
    }

    /**
     * A successful registration with a writable .env reports wrote_env=true and
     * MUST NOT carry the raw token back to the caller.
     *
     * @return void
     */
    public function test_success_writable_env_omits_token(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['license' => 'SECRET-TOKEN']], 200)]);

        $result = $this->registrarWithEnvWrite(true)->register();

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['wrote_env']);
        $this->assertArrayNotHasKey('license', $result);
    }

    /**
     * A successful registration whose .env is read-only still succeeds but hands
     * the token back so the operator can paste it manually — the one branch
     * allowed to expose the token.
     *
     * @return void
     */
    public function test_success_readonly_env_returns_token(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['license' => 'SECRET-TOKEN']], 200)]);

        $result = $this->registrarWithEnvWrite(false)->register();

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['wrote_env']);
        $this->assertSame('SECRET-TOKEN', $result['license']);
    }

    /**
     * A marketplace error is surfaced as status=error with no token and no
     * .env write.
     *
     * @return void
     */
    public function test_marketplace_error_is_reported(): void
    {
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Domain not authorized'], 403)]);

        $result = $this->registrarWithEnvWrite(true)->register();

        $this->assertSame('error', $result['status']);
        $this->assertFalse($result['wrote_env']);
        $this->assertArrayNotHasKey('license', $result);
    }

    /**
     * A "success" response that carries no license token is treated as an error,
     * not a spurious success (defensive against a malformed marketplace reply).
     *
     * @return void
     */
    public function test_empty_license_is_error(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => []], 200)]);

        $result = $this->registrarWithEnvWrite(true)->register();

        $this->assertSame('error', $result['status']);
        $this->assertFalse($result['wrote_env']);
        $this->assertArrayNotHasKey('license', $result);
    }
}
