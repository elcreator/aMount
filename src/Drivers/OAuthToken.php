<?php

namespace Elcreator\aMount\Drivers;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Exchanges a long-lived refresh token for a short-lived access token, and
 * remembers the result until it is nearly stale.
 *
 * Google Drive, Dropbox and OneDrive all hand out access tokens that live about
 * an hour, while an upload run lives for as long as the files take. Asking for a
 * fresh token on every filesystem build would burn the provider's token quota;
 * the cache keeps one per credential set, keyed by a hash so the secret itself
 * never becomes a cache key.
 */
final class OAuthToken
{
    /**
     * @param array<string, string> $form the token request body
     * @throws RuntimeException when the provider refuses
     */
    public static function get(string $identity, string $url, array $form): string
    {
        $key = 'amount.oauth.' . sha1($identity . '|' . $url);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = (new Client(['timeout' => 30]))->post($url, ['form_params' => $form, 'http_errors' => false]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not reach the token endpoint: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data) || empty($data['access_token'])) {
            $reason = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? 'no access token in the answer') : 'unreadable answer';

            throw new RuntimeException('The provider refused the refresh token: ' . $reason);
        }

        Cache::put($key, (string) $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3600) - 120));

        return (string) $data['access_token'];
    }

    public static function forget(string $identity, string $url): void
    {
        Cache::forget('amount.oauth.' . sha1($identity . '|' . $url));
    }
}
