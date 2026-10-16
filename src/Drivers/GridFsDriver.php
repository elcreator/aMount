<?php

namespace Elcreator\aMount\Drivers;

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\GridFS\GridFSAdapter;
use MongoDB\Client;

final class GridFsDriver extends Driver
{
    public function id(): string
    {
        return 'gridfs';
    }

    public function label(): string
    {
        return 'MongoDB GridFS';
    }

    public function group(): string
    {
        return self::GROUP_OTHER;
    }

    public function packages(): array
    {
        return ['league/flysystem-gridfs'];
    }

    protected function probeClass(): string
    {
        return GridFSAdapter::class;
    }

    public function available(): bool
    {
        return parent::available() && extension_loaded('mongodb');
    }

    public function needsPublicUrl(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            self::secret('uri', true, ['help' => 'aMount::global.h_gridfs_uri']),
            self::text('database', true),
            self::text('bucket_name', false, ['default' => 'fs']),
            self::text('prefix', false, ['help' => 'aMount::global.h_prefix']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $bucket = (new Client(trim((string) $config['uri'])))
            ->selectDatabase(trim((string) $config['database']))
            ->selectGridFSBucket(['bucketName' => trim((string) ($config['bucket_name'] ?? '')) ?: 'fs']);

        return new GridFSAdapter($bucket, self::prefix($config));
    }
}
