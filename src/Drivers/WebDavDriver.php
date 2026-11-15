<?php

namespace Elcreator\aMount\Drivers;

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\WebDAV\WebDAVAdapter;
use RuntimeException;
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

    public function validate(array $config): array
    {
        $errors = parent::validate($config);
        $uri = trim((string) ($config['base_uri'] ?? ''));

        if ($uri !== '' && !preg_match('#^https?://[^/\s]+#i', $uri)) {
            $errors['base_uri'] = 'aMount::global.error_webdav_uri';
        }

        return $errors;
    }

    public function destinations(array $config): array
    {
        return ['base_uri' => trim((string) ($config['base_uri'] ?? ''))];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        return new WebDAVAdapter(self::client($config, $options['_pinned'] ?? null), self::prefix($config));
    }

    /**
     * With $pinned (settings not chosen by an administrator) cURL connects to
     * the address that was checked, never re-resolving the name, and no
     * redirect is followed: either would let a public URL lead to an internal one.
     *
     * @param array<string, string>|null $pinned host => address
     */
    public static function client(array $config, ?array $pinned): Client
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

        if ($pinned === null) {
            return new Client($settings);
        }

        $client = new class ($settings) extends Client {
            public function __construct(array $settings)
            {
                parent::__construct($settings);
                $this->maxRedirects = 0;
            }
        };

        $parts = parse_url($settings['baseUri']);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $address = $pinned[$host] ?? null;

        if ($address === null) {
            throw new RuntimeException('WebDAV: ' . $host . ' was not checked.');
        }

        $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80));
        $client->addCurlSetting(CURLOPT_RESOLVE, [sprintf('%s:%d:%s', $host, $port, str_contains($address, ':') ? '[' . $address . ']' : $address)]);

        return $client;
    }
}
