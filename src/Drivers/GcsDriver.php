<?php

namespace Elcreator\aMount\Drivers;

use Google\Cloud\Storage\StorageClient;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;

final class GcsDriver extends Driver
{
    public function id(): string
    {
        return 'gcs';
    }

    public function label(): string
    {
        return 'Google Cloud Storage';
    }

    public function group(): string
    {
        return self::GROUP_OBJECT;
    }

    public function packages(): array
    {
        return ['league/flysystem-google-cloud-storage'];
    }

    protected function probeClass(): string
    {
        return GoogleCloudStorageAdapter::class;
    }

    public function supportsVisibility(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            self::text('bucket', true),
            self::text('project_id'),
            self::area('key_file', true, ['help' => 'aMount::global.h_gcs_key']),
            self::text('prefix', false, ['help' => 'aMount::global.h_prefix']),
        ];
    }

    public function validate(array $config): array
    {
        $errors = parent::validate($config);

        if (!isset($errors['key_file']) && json_decode((string) $config['key_file'], true) === null) {
            $errors['key_file'] = 'aMount::global.error_json';
        }

        return $errors;
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $key = json_decode((string) $config['key_file'], true);
        $client = new StorageClient(array_filter([
            'projectId' => trim((string) ($config['project_id'] ?? '')) ?: ($key['project_id'] ?? null),
            'keyFile' => $key,
        ]));

        return new GoogleCloudStorageAdapter($client->bucket(trim((string) $config['bucket'])), self::prefix($config));
    }
}
