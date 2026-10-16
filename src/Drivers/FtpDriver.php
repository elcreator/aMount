<?php

namespace Elcreator\aMount\Drivers;

use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\FilesystemAdapter;

final class FtpDriver extends Driver
{
    public function id(): string
    {
        return 'ftp';
    }

    public function label(): string
    {
        return 'FTP / FTPS';
    }

    public function group(): string
    {
        return self::GROUP_SERVER;
    }

    public function packages(): array
    {
        return ['league/flysystem-ftp'];
    }

    /** The adapter needs PHP's ftp extension as well as its package; many builds ship without it. */
    public function available(): bool
    {
        return parent::available() && extension_loaded('ftp');
    }

    protected function probeClass(): string
    {
        return FtpAdapter::class;
    }

    public function supportsVisibility(): bool
    {
        return true;
    }

    public function needsPublicUrl(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            self::text('host', true),
            self::number('port', 21),
            self::text('username', true),
            self::secret('password', true),
            self::toggle('ssl', false, ['help' => 'aMount::global.h_ftp_ssl']),
            self::toggle('passive', true),
            self::text('root', true, ['default' => '/']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        return new FtpAdapter(FtpConnectionOptions::fromArray([
            'host' => trim((string) $config['host']),
            'root' => '/' . trim((string) ($config['root'] ?? '/'), '/'),
            'username' => trim((string) $config['username']),
            'password' => (string) $config['password'],
            'port' => (int) ($config['port'] ?? 21) ?: 21,
            'ssl' => (bool) ($config['ssl'] ?? false),
            'passive' => (bool) ($config['passive'] ?? true),
            'timeout' => 60,
        ]));
    }
}
