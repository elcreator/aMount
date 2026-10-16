<?php

namespace Elcreator\aMount\Drivers;

use AlphaSnow\Flysystem\Aliyun\AliyunAdapter;
use AlphaSnow\Flysystem\Aliyun\AliyunFactory;
use League\Flysystem\FilesystemAdapter;

final class AliyunDriver extends Driver
{
    public function id(): string
    {
        return 'aliyun';
    }

    public function label(): string
    {
        return 'Alibaba Cloud OSS';
    }

    public function group(): string
    {
        return self::GROUP_OBJECT;
    }

    public function packages(): array
    {
        return ['alphasnow/aliyun-oss-flysystem'];
    }

    protected function probeClass(): string
    {
        return AliyunAdapter::class;
    }

    public function fields(): array
    {
        return [
            self::text('bucket', true),
            self::text('endpoint', true, ['default' => 'oss-cn-hangzhou.aliyuncs.com']),
            self::text('access_key_id', true),
            self::secret('access_key_secret', true),
            self::text('domain', false, ['help' => 'aMount::global.h_aliyun_domain']),
            self::text('prefix', false, ['help' => 'aMount::global.h_prefix']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        return (new AliyunFactory())->createAdapter([
            'bucket' => trim((string) $config['bucket']),
            'endpoint' => trim((string) $config['endpoint']),
            'internal' => '',
            'domain' => trim((string) ($config['domain'] ?? '')),
            'is_cname' => trim((string) ($config['domain'] ?? '')) !== '',
            'use_ssl' => true,
            'access_key_id' => trim((string) $config['access_key_id']),
            'access_key_secret' => trim((string) $config['access_key_secret']),
            'prefix' => self::prefix($config),
        ]);
    }
}
