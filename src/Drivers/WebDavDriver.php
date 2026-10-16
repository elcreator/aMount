<?php

namespace Elcreator\aMount\Drivers;

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\WebDAV\WebDAVAdapter;
use Sabre\DAV\Client;

/**
 * Nextcloud, ownCloud, Synology, Yandex.Disk (webdav.yandex.com), Box,
 * pCloud ... anything with a WebDAV endpoint.
 */
final class WebDavDriver extends Driver
{
    public function id(): string
    {
        return 'webdav';
    }

    public function label(): string
    {
        return 'WebDAV (Nextcloud, ownCloud ...)';
    }

    public function group(): string
    {
        return self::GROUP_SERVER;
    }

    public function packages(): array
    {
        return ['league/flysystem-webdav'];
    }

    protected function probeClass(): string
    {
        return WebDAVAdapter::class;
    }

    public function needsPublicUrl(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            self::text('base_uri', true, ['help' => 'aMount::global.h_webdav_uri']),
            self::text('username'),
            self::secret('password'),
            self::select('auth', ['basic' => 'Basic', 'digest' => 'Digest', 'ntlm' => 'NTLM'], 'basic'),
            self::text('prefix', false, ['help' => 'aMount::global.h_prefix']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $settings = ['baseUri' => rtrim(trim((string) $config['base_uri']), '/') . '/'];

        if (trim((string) ($config['username'] ?? '')) !== '') {
            $settings['userName'] = trim((string) $config['username']);
            $settings['password'] = (string) ($config['password'] ?? '');
            $settings['authType'] = match ((string) ($config['auth'] ?? 'basic')) {
                'digest' => Client::AUTH_DIGEST,
                'ntlm' => Client::AUTH_NTLM,
                default => Client::AUTH_BASIC,
            };
        }

        return new WebDAVAdapter(new Client($settings), self::prefix($config));
    }
}
