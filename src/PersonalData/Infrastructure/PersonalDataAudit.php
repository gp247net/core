<?php

namespace GP247\Core\PersonalData\Infrastructure;

use GP247\Core\Models\AdminLog;

/**
 * A row in the admin operation log for each re-confirmation before an erasure (passed,
 * refused, throttled), so a failed attempt to erase a customer leaves a trace. Only a result
 * and a reason code are written — never the typed password or customer data.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr personal-data-registry_shared-step-up
 */
final class PersonalDataAudit
{
    public const METHOD = 'PRIVACY';
    private const NO_ADMIN_ID = '00000000-0000-0000-0000-000000000000';
    private const CONTEXT_KEYS = ['reason', 'retry_after'];

    /**
     * @param string               $event   e.g. step_up.
     * @param string               $result  ok | refused | throttled.
     * @param array<string, mixed> $context Only reason / retry_after are kept.
     * @return void
     */
    public static function record(string $event, string $result, array $context = []): void
    {
        try {
            $user = function_exists('admin') ? admin()->user() : null;
            $input = ['action' => $event, 'result' => $result];
            foreach (self::CONTEXT_KEYS as $key) {
                if (isset($context[$key]) && is_scalar($context[$key])) {
                    $input[$key] = mb_substr((string) $context[$key], 0, 100);
                }
            }
            AdminLog::create([
                'user_id' => $user !== null ? (string) $user->getAuthIdentifier() : self::NO_ADMIN_ID,
                'path' => mb_substr(GP247_ADMIN_PREFIX . '/personal-data/' . $event, 0, 255),
                'method' => self::METHOD,
                'ip' => (string) (gp247_get_real_ip_client() ?? ''),
                'user_agent' => mb_substr((string) request()->header('User-Agent', ''), 0, 255),
                'input' => (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);
        } catch (\Throwable $e) {
            gp247_report('Personal data audit row not written: ' . $e->getMessage());
        }
    }
}
