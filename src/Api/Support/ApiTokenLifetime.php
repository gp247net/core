<?php

namespace GP247\Core\Api\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lifetime rules for REST API tokens: every token gets an expiry, long-expired
 * tokens of an owner are purged at that owner's next login (no scheduler needed),
 * and the legacy GET logout is flagged as deprecated.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-token-lifetime
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class ApiTokenLifetime
{
    /** Tokens expired for longer than this are deleted at the owner's next login. */
    private const PURGE_AFTER_DAYS = 7;

    /**
     * Expiry for a token living $days days (at least one day).
     *
     * @param mixed $days Configured lifetime in days.
     * @return CarbonInterface
     */
    public static function expiresAt($days): CarbonInterface
    {
        return now()->addDays(max(1, (int) $days));
    }

    /**
     * Delete tokens of $owner expired more than PURGE_AFTER_DAYS ago.
     *
     * @param \Illuminate\Database\Eloquent\Model $owner Tokenable model (HasApiTokens).
     * @return void
     */
    public static function purgeExpired($owner): void
    {
        $owner->tokens()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subDays(self::PURGE_AFTER_DAYS))
            ->delete();
    }

    /**
     * Add the Deprecation/Link headers when logout was called with GET.
     *
     * @param Request  $request
     * @param Response $response
     * @return Response
     */
    public static function withLogoutDeprecation(Request $request, Response $response): Response
    {
        if ($request->isMethod('GET')) {
            $response->headers->set('Deprecation', 'true');
            $response->headers->set('Link', '<https://gp247.net/en/docs/api/about-api-gp247.html>; rel="deprecation"');
        }
        return $response;
    }
}
