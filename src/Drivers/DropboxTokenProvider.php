<?php

namespace Elcreator\aMount\Drivers;

use GuzzleHttp\Exception\ClientException;
use Spatie\Dropbox\RefreshableTokenProvider;

/**
 * Dropbox access tokens expire after about four hours. The client calls
 * refresh() when Dropbox answers 401, retries once, and carries on.
 */
final class DropboxTokenProvider implements RefreshableTokenProvider
{
    private const URL = 'https://api.dropboxapi.com/oauth2/token';

    public function __construct(
        private readonly string $appKey,
        private readonly string $appSecret,
        private readonly string $refreshToken
    ) {
    }

    public function getToken(): string
    {
        return OAuthToken::get($this->identity(), self::URL, $this->form());
    }

    public function refresh(ClientException $exception): bool
    {
        OAuthToken::forget($this->identity(), self::URL);

        try {
            $this->getToken();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function identity(): string
    {
        return $this->appKey . '|' . $this->refreshToken;
    }

    private function form(): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->appKey,
            'client_secret' => $this->appSecret,
        ];
    }
}
