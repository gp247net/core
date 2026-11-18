<?php

namespace GP247\Core\SecurityHeaders\Infrastructure;

use GP247\Core\SecurityHeaders\Application\HeaderPolicyResolver;
use GP247\Core\SecurityHeaders\Domain\HeaderPolicy;
use GP247\Core\SecurityHeaders\Domain\ResponseKind;
use Illuminate\Support\Facades\Http;

/**
 * Reads the headers the site really answers with (after the web server / CDN) and compares
 * them with the settings: missing, sent twice, or sent although switched off here. Used
 * only by `gp247:doctor --probe-headers`; a site that cannot be reached (shared hosts often
 * block calls to themselves) is "not checked", never an error.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-doctor-checks, US-security-headers-respect-server
 * @aidlc-adr security-headers_doctor-live-probe
 */
final class LiveHeaderProbe
{
    private const TIMEOUT = 5;

    private const MANAGED = [HeaderPolicy::FRAME, HeaderPolicy::NOSNIFF, HeaderPolicy::REFERRER, HeaderPolicy::PERMISSIONS, HeaderPolicy::HSTS];

    /**
     * @param HeaderPolicyResolver $resolver
     */
    public function __construct(private readonly HeaderPolicyResolver $resolver)
    {
    }

    /**
     * Probe the site address and describe what differs from the settings.
     *
     * @param string $url Site address (APP_URL).
     * @return array{status: string, detail: string, items: list<string>} status `pass` or `warn`.
     */
    public function probe(string $url): array
    {
        try {
            $response = $this->fetch($url);
        } catch (\Throwable $e) {
            return ['status' => 'pass', 'detail' => 'could not check: ' . $e->getMessage(), 'items' => []];
        }
        $policy = $this->resolver->current();
        $expected = $policy?->headersFor(ResponseKind::Html, SecurityHeadersCheck::isHttps($url)) ?? [];
        $psr = $response->toPsrResponse();
        $items = [];
        foreach (self::MANAGED as $name) {
            $values = $psr->getHeader($name);
            if (isset($expected[$name]) && $values === []) {
                $items[] = 'missing: ' . $name;
            } elseif (count($values) > 1) {
                $items[] = 'sent twice: ' . $name;
            } elseif (!isset($expected[$name]) && $values !== []) {
                $items[] = 'switched off but still sent: ' . $name;
            }
        }

        return [
            'status' => $items === [] ? 'pass' : 'warn',
            'detail' => $items === [] ? 'live headers match the settings' : 'live headers differ from the settings (web server, CDN or plugin)',
            'items' => $items,
        ];
    }

    /**
     * GET the address; follow one redirect only when it stays on the same host.
     *
     * @param string $url
     * @return \Illuminate\Http\Client\Response
     * @throws \RuntimeException When the site redirects to another host.
     */
    private function fetch(string $url)
    {
        $response = Http::timeout(self::TIMEOUT)->withoutRedirecting()->get($url);
        if ($response->redirect()) {
            $location = (string) $response->header('Location');
            $target = parse_url($location, PHP_URL_HOST) === null ? rtrim($url, '/') . '/' . ltrim($location, '/') : $location;
            // WHY same host only: the probe must not be pointed at another server by a redirect.
            if (strcasecmp((string) parse_url($target, PHP_URL_HOST), (string) parse_url($url, PHP_URL_HOST)) !== 0) {
                throw new \RuntimeException('redirected to another host');
            }
            $response = Http::timeout(self::TIMEOUT)->withoutRedirecting()->get($target);
        }

        return $response;
    }
}
