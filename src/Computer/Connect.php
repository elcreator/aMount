<?php

namespace Elcreator\aMount\Computer;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\RequestOptions;

/**
 * "Connect LendSpare": a key for the manager's computers without anybody copying one.
 *
 * OAuth2 authorisation code with PKCE (S256), as aIMage and aDataForSeo connect to their gateway.
 * The manager's browser goes to `{lendspare}/sites/connect?app=amount&site&redirect_uri&state
 * &code_challenge`, the signed-in person allows read, or read and write, and the browser comes back
 * with `?code&state`. The code is traded for a key here, server to server, with the verifier only
 * this side knows: the key never passes through the browser.
 *
 * The way back stops at a public page first ({@see relayUri()}): the manager refuses a request whose
 * Referer is another site, and the browser coming back from LendSpare names LendSpare.
 */
final class Connect
{
    public const APP = 'amount';

    /** The public stop on the way back; it forwards to the manager callback with no Referer. */
    public const RELAY_PATH = '/amount/lendspare-return';

    /** How long a started connection may take, seconds. */
    public const PENDING_TTL = 900;

    /** What LendSpare may send back; anything else is dropped. */
    private const FORWARDED = ['code', 'state', 'error', 'error_description'];

    /** Tests replace the HTTP client. */
    public static ?HttpClient $http = null;

    /** @return array{verifier: string, challenge: string, state: string} */
    public static function pkce(): array
    {
        $verifier = self::base64url(random_bytes(48));

        return ['verifier' => $verifier, 'challenge' => self::challenge($verifier), 'state' => self::base64url(random_bytes(24))];
    }

    /** RFC 7636 S256. */
    public static function challenge(string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    /** LendSpare's own origin, from its API address (`https://lendspare.com/api/v1` -> `https://lendspare.com`). */
    public static function lendspareOrigin(?string $apiUrl = null): string
    {
        $parts = parse_url($apiUrl ?? Gateway::defaultUrl());
        if (!is_array($parts) || empty($parts['host'])) {
            return 'https://lendspare.com';
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public static function authorizeUrl(string $site, string $redirectUri, string $state, string $challenge, ?string $apiUrl = null): string
    {
        return self::lendspareOrigin($apiUrl) . '/sites/connect?' . http_build_query([
            'app' => self::APP,
            'site' => $site,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** This site's origin as LendSpare wants it: scheme and host, no path. */
    public static function siteOrigin(string $siteUrl): string
    {
        $parts = parse_url($siteUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return rtrim($siteUrl, '/');
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Trade the code for a key.
     *
     * @throws ComputerException with LendSpare's own sentence ("expired", "already used"), never the key
     */
    public static function exchange(string $code, string $verifier, string $redirectUri, ?string $apiUrl = null): string
    {
        $http = self::$http ?? new HttpClient(['http_errors' => false, 'timeout' => 30, 'connect_timeout' => 10]);
        $response = $http->request('POST', self::lendspareOrigin($apiUrl) . '/sites/connect/token', [
            RequestOptions::JSON => ['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirectUri],
            RequestOptions::HEADERS => ['Accept' => 'application/json', 'User-Agent' => 'aMount (Evolution CMS)'],
        ]);
        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : [];
        $key = $body['access_token'] ?? null;
        if ($response->getStatusCode() >= 400 || !is_string($key) || $key === '') {
            $detail = is_string($body['error_description'] ?? null) ? $body['error_description'] : 'HTTP ' . $response->getStatusCode();
            throw new ComputerException(is_string($body['error'] ?? null) ? $body['error'] : 'connect_failed', mb_substr($detail, 0, 200), $response->getStatusCode());
        }

        return $key;
    }

    /** The `redirect_uri` handed to LendSpare: this site's relay, and the manager callback path it forwards to. */
    public static function relayUri(string $siteOrigin, string $managerCallbackUrl): string
    {
        $path = (string) parse_url($managerCallbackUrl, PHP_URL_PATH);

        return rtrim($siteOrigin, '/') . self::RELAY_PATH . '?' . http_build_query(['m' => $path], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Where the relay forwards to: the manager callback path with only LendSpare's answer, or null.
     * The target is a path on this host matching the callback route, never a URL from the query.
     *
     * @param array<string, mixed> $query
     */
    public static function relayTarget(array $query): ?string
    {
        $path = $query['m'] ?? null;
        if (!is_string($path) || !preg_match('#^/[A-Za-z0-9._~/-]{1,200}/computers/connect/callback$#', $path)
            || str_contains($path, '..') || str_contains($path, '//')) {
            return null;
        }
        $forward = [];
        foreach (self::FORWARDED as $name) {
            if (is_string($query[$name] ?? null)) {
                $forward[$name] = mb_substr($query[$name], 0, 300);
            }
        }

        return $path . ($forward === [] ? '' : '?' . http_build_query($forward, '', '&', PHP_QUERY_RFC3986));
    }

    /** A page that forwards at once; the header and the meta tag both ask for no Referer. */
    public static function relayPage(string $target): string
    {
        $safe = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer">'
            . '<meta http-equiv="refresh" content="0;url=' . $safe . '"><title>aMount</title></head>'
            . '<body><p><a href="' . $safe . '" referrerpolicy="no-referrer" rel="noreferrer">&rarr;</a></p>'
            . '<script>location.replace(' . json_encode($target, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . ');</script></body></html>';
    }

    /**
     * The module page inside the manager frame (`/manager/#/modules/...`), so the person lands in the
     * manager rather than on the bare module route.
     */
    public static function framedUrl(string $pageUrl, string $managerUrl): string
    {
        if ($managerUrl === '') {
            return $pageUrl;
        }
        $base = rtrim((string) parse_url($managerUrl, PHP_URL_PATH), '/') . '/';
        $parts = parse_url($pageUrl);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if (!str_starts_with($path, $base) || $path === $base) {
            return $pageUrl;
        }

        return rtrim($managerUrl, '/') . '/#' . substr($path, strlen($base))
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
