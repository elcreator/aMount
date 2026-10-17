<?php

namespace Elcreator\aMount\Drivers;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\FilesystemAdapter;

/**
 * Amazon S3 and everything that speaks its API: Cloudflare R2, DigitalOcean
 * Spaces, MinIO, Wasabi, Backblaze B2, Hetzner Object Storage, Scaleway,
 * Supabase Storage ...
 *
 * `preset` is a form helper only: choosing one fills `endpoint`, `region` and
 * `path_style`, which are what is stored. A preset that goes out of date never
 * breaks a saved mount.
 */
final class S3Driver extends Driver
{
    /** value => [label, endpoint template, region, path style] */
    public const PRESETS = [
        'aws' => ['Amazon S3', '', 'us-east-1', false],
        'r2' => ['Cloudflare R2', 'https://<account-id>.r2.cloudflarestorage.com', 'auto', true],
        'spaces' => ['DigitalOcean Spaces', 'https://<region>.digitaloceanspaces.com', 'fra1', false],
        'wasabi' => ['Wasabi', 'https://s3.<region>.wasabisys.com', 'eu-central-1', false],
        'b2' => ['Backblaze B2', 'https://s3.<region>.backblazeb2.com', 'us-west-004', true],
        'minio' => ['MinIO (self-hosted)', 'http://minio:9000', 'us-east-1', true],
        'hetzner' => ['Hetzner Object Storage', 'https://<location>.your-objectstorage.com', 'fsn1', true],
        'scaleway' => ['Scaleway Object Storage', 'https://s3.<region>.scw.cloud', 'fr-par', false],
        'supabase' => ['Supabase Storage', 'https://<project>.storage.supabase.co/storage/v1/s3', 'us-east-1', true],
        'other' => ['Other S3-compatible', '', 'us-east-1', true],
    ];

    public function id(): string
    {
        return 's3';
    }

    public function label(): string
    {
        return 'Amazon S3 and S3-compatible';
    }

    public function group(): string
    {
        return self::GROUP_OBJECT;
    }

    public function packages(): array
    {
        return ['league/flysystem-aws-s3-v3'];
    }

    protected function probeClass(): string
    {
        return AwsS3V3Adapter::class;
    }

    public function supportsVisibility(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            self::select('preset', array_map(static fn ($p) => $p[0], self::PRESETS), 'aws'),
            self::text('bucket', true),
            self::text('region', false, ['default' => 'us-east-1']),
            self::text('endpoint', false, ['help' => 'aMount::global.h_s3_endpoint']),
            self::toggle('path_style', false),
            self::text('key', false, ['help' => 'aMount::global.h_s3_key']),
            self::secret('secret'),
            self::secret('token', false, ['help' => 'aMount::global.h_s3_token']),
            self::text('prefix', false, ['help' => 'aMount::global.h_prefix']),
        ];
    }

    public function validate(array $config): array
    {
        $errors = parent::validate($config);

        // No key at all is legitimate (instance profile, env credentials);
        // a key without its secret never is.
        if (trim((string) ($config['key'] ?? '')) !== '' && trim((string) ($config['secret'] ?? '')) === '') {
            $errors['secret'] = 'aMount::global.error_field_required';
        }

        return $errors;
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $arguments = [
            'version' => 'latest',
            'region' => trim((string) ($config['region'] ?? '')) ?: 'us-east-1',
        ];

        if (trim((string) ($config['key'] ?? '')) !== '') {
            $arguments['credentials'] = array_filter([
                'key' => trim((string) $config['key']),
                'secret' => trim((string) $config['secret']),
                'token' => trim((string) ($config['token'] ?? '')) ?: null,
            ]);
        }

        if (trim((string) ($config['endpoint'] ?? '')) !== '') {
            $arguments['endpoint'] = trim((string) $config['endpoint']);
            $arguments['use_path_style_endpoint'] = (bool) ($config['path_style'] ?? false);
            // Newer SDKs add CRC checksums that several S3-compatible servers
            // do not understand (R2, MinIO, B2 reject them).
            $arguments['request_checksum_calculation'] = 'when_required';
            $arguments['response_checksum_validation'] = 'when_required';
        }

        return new AwsS3V3Adapter(new S3Client($arguments), trim((string) $config['bucket']), self::prefix($config));
    }
}
