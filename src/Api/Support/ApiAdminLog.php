<?php

namespace GP247\Core\Api\Support;

use GP247\Core\AdminShell\Support\LivewireOperationLog;
use GP247\Core\Middleware\LogOperation;
use GP247\Core\Models\AdminLog;
use GP247\Core\Models\AdminUser;
use Illuminate\Http\Request;

/**
 * Operation log for the admin REST API: one `admin_log` row per authenticated request,
 * as the admin web screens already write. Called from the two gates every admin API
 * route passes (api.admin, api.admin.active), so routes of front/shop/plugins are
 * covered without declaring anything. Secrets are masked with the web's except list;
 * response bodies are never logged.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-admin-access-log
 * @aidlc-adr compat-foundation_api-token-rotation
 */
final class ApiAdminLog
{
    private const DONE = 'gp247.api_admin_logged';

    /** API credentials masked on top of the web's except list. */
    private const API_SECRETS = ['refresh_token', 'ticket', 'code', 'otp', 'token', 'password_old', 'password_confirmation', 'current_password'];

    /**
     * Record $request once, unless the operation log is switched off.
     *
     * @param Request   $request
     * @param AdminUser $user Authenticated token owner.
     * @return void
     */
    public static function record(Request $request, AdminUser $user): void
    {
        if ($request->attributes->get(self::DONE) || !config('gp247-config.admin.admin_log')) {
            return;
        }
        $request->attributes->set(self::DONE, true);

        $input = json_encode($request->except(array_merge(LogOperation::exceptKeys(), self::API_SECRETS)));
        if ($input === false || strlen($input) > LivewireOperationLog::INPUT_MAX) {
            $input = json_encode(['_truncated' => true]);
        }
        try {
            AdminLog::create(gp247_clean(data: [
                'user_id'    => $user->id,
                'path'       => substr($request->path(), 0, 255),
                'method'     => $request->method(),
                'ip'         => gp247_get_real_ip_client(),
                'user_agent' => substr((string) $request->header('User-Agent', ''), 0, 255),
                'input'      => $input,
            ], hight: true));
        } catch (\Throwable $e) {
            // WHY: a failing audit write must not break the API call it describes.
            gp247_report($e->getMessage());
        }
    }
}
