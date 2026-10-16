<?php

namespace Elcreator\aMount\Drivers;

use League\Flysystem\FilesystemAdapter;
use Spatie\Dropbox\Client;
use Spatie\FlysystemDropbox\DropboxAdapter;

final class DropboxDriver extends Driver
{
    public function id(): string
    {
        return 'dropbox';
    }

    public function label(): string
    {
        return 'Dropbox';
    }

    public function group(): string
    {
        return self::GROUP_CLOUD;
    }

    public function packages(): array
    {
        return ['spatie/flysystem-dropbox'];
    }

    protected function probeClass(): string
    {
        return DropboxAdapter::class;
    }

    public function fields(): array
    {
        return [
            self::text('app_key', false, ['help' => 'aMount::global.h_dropbox']),
            self::secret('app_secret'),
            self::secret('refresh_token'),
            self::secret('access_token', false, ['help' => 'aMount::global.h_dropbox_token']),
            self::text('prefix', false, ['help' => 'aMount::global.h_prefix']),
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];
        $refresh = trim((string) ($config['refresh_token'] ?? '')) !== '';

        if ($refresh) {
            foreach (['app_key', 'app_secret'] as $field) {
                if (trim((string) ($config[$field] ?? '')) === '') {
                    $errors[$field] = 'aMount::global.error_field_required';
                }
            }
        } elseif (trim((string) ($config['access_token'] ?? '')) === '') {
            $errors['refresh_token'] = 'aMount::global.error_field_required';
        }

        return $errors;
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $client = trim((string) ($config['refresh_token'] ?? '')) !== ''
            ? new Client(new DropboxTokenProvider(
                trim((string) $config['app_key']),
                trim((string) $config['app_secret']),
                trim((string) $config['refresh_token'])
            ))
            : new Client(trim((string) $config['access_token']));

        return new DropboxAdapter($client, self::prefix($config));
    }
}
