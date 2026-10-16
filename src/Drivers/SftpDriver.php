<?php

namespace Elcreator\aMount\Drivers;

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

final class SftpDriver extends Driver
{
    public function id(): string
    {
        return 'sftp';
    }

    public function label(): string
    {
        return 'SFTP / SSH';
    }

    public function group(): string
    {
        return self::GROUP_SERVER;
    }

    public function packages(): array
    {
        return ['league/flysystem-sftp-v3'];
    }

    protected function probeClass(): string
    {
        return SftpAdapter::class;
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
            self::number('port', 22),
            self::text('username', true),
            self::secret('password'),
            self::area('private_key', false, ['help' => 'aMount::global.h_sftp_key']),
            self::secret('passphrase'),
            self::text('host_fingerprint', false, ['secret' => false, 'help' => 'aMount::global.h_sftp_fingerprint']),
            self::text('root', true, ['default' => '/', 'help' => 'aMount::global.h_sftp_root']),
        ];
    }

    public function validate(array $config): array
    {
        $errors = parent::validate($config);

        if (trim((string) ($config['password'] ?? '')) === '' && trim((string) ($config['private_key'] ?? '')) === '') {
            $errors['password'] = 'aMount::global.error_field_required';
        }

        return $errors;
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $key = trim((string) ($config['private_key'] ?? ''));

        return new SftpAdapter(
            new SftpConnectionProvider(
                host: trim((string) $config['host']),
                username: trim((string) $config['username']),
                password: ($config['password'] ?? '') !== '' ? (string) $config['password'] : null,
                privateKey: $key !== '' ? $key : null,
                passphrase: ($config['passphrase'] ?? '') !== '' ? (string) $config['passphrase'] : null,
                port: (int) ($config['port'] ?? 22) ?: 22,
                timeout: 30,
                maxTries: 3,
                hostFingerprint: trim((string) ($config['host_fingerprint'] ?? '')) ?: null
            ),
            '/' . trim((string) ($config['root'] ?? '/'), '/')
        );
    }
}
