<?php

namespace Elcreator\aMount\Drivers;

use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\FilesystemAdapter;

/**
 * S3 through AsyncAws: the same buckets as the S3 driver with a far lighter
 * dependency than the AWS SDK. Pick it when the SDK's size matters.
 */
final class AsyncS3Driver extends Driver
{
    public function id(): string
    {
        return 'async-s3';
    }

    public function label(): string
    {
        return 'S3 via AsyncAws';
    }

    public function group(): string
    {
        return self::GROUP_OBJECT;
    }

    public function packages(): array
    {
        return ['league/flysystem-async-aws-s3'];
    }

    protected function probeClass(): string
    {
        return AsyncAwsS3Adapter::class;
    }

    public function supportsVisibility(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            self::text('bucket', true),
            self::text('region', false, ['default' => 'us-east-1']),
            self::text('endpoint', false, ['help' => 'aMount::global.h_s3_endpoint']),
            self::toggle('path_style', false),
            self::text('key', false, ['help' => 'aMount::global.h_s3_key']),
            self::secret('secret'),
            self::text('prefix', false, ['help' => 'aMount::global.h_prefix']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $arguments = ['region' => trim((string) ($config['region'] ?? '')) ?: 'us-east-1'];

        if (trim((string) ($config['key'] ?? '')) !== '') {
            $arguments['accessKeyId'] = trim((string) $config['key']);
            $arguments['accessKeySecret'] = trim((string) ($config['secret'] ?? ''));
        }

        if (trim((string) ($config['endpoint'] ?? '')) !== '') {
            $arguments['endpoint'] = trim((string) $config['endpoint']);
            $arguments['pathStyleEndpoint'] = (bool) ($config['path_style'] ?? false);
        }

        return new AsyncAwsS3Adapter(new S3Client($arguments), trim((string) $config['bucket']), self::prefix($config));
    }
}
