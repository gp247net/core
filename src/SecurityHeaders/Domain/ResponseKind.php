<?php

namespace GP247\Core\SecurityHeaders\Domain;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a response is, as far as security headers care: a page, a JSON answer, or a file.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-defaults
 * @aidlc-adr security-headers_response-middleware
 */
enum ResponseKind: string
{
    case Html = 'html';
    case Json = 'json';
    case File = 'file';

    /**
     * Classify a response by its class, Content-Disposition and Content-Type.
     *
     * @param Response $response
     * @return self
     */
    public static function of(Response $response): self
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return self::File;
        }
        if (str_starts_with(strtolower((string) $response->headers->get('Content-Disposition')), 'attachment')) {
            return self::File;
        }
        $type = strtolower((string) $response->headers->get('Content-Type'));
        // WHY "json" anywhere: covers application/json, application/problem+json, vnd.api+json.
        if (str_contains($type, 'json')) {
            return self::Json;
        }

        return self::Html;
    }
}
