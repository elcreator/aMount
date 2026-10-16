<?php

namespace Elcreator\aMount\Drivers;

use League\Flysystem\FilesystemAdapter;
use Overtrue\Flysystem\Cos\CosAdapter;

final class CosDriver extends Driver
{
    public function id(): string
    {
        return 'cos';
    }

    public function label(): string
    {
        return 'Tencent Cloud COS';
    }

    public function group(): string
    {
        return self::GROUP_OBJECT;
    }

    public function packages(): array
    {
        return ['overtrue/flysystem-cos'];
    }

    protected function probeClass(): string
    {
        return CosAdapter::class;
    }

    public function fields(): array
    {
        return [
            self::text('bucket', true),
            self::text('app_id', true),
            self::text('region', true, ['default' => 'ap-guangzhou']),
            self::text('secret_id', true),
            self::secret('secret_key', true),
            self::text('cdn', false, ['help' => 'aMount::global.h_cos_cdn']),
            self::text('prefix', false, ['help' => 'aMount::global.h_prefix']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        return new CosAdapter(array_filter([
            'bucket' => trim((string) $config['bucket']),
            'app_id' => trim((string) $config['app_id']),
            'region' => trim((string) $config['region']),
            'secret_id' => trim((string) $config['secret_id']),
            'secret_key' => trim((string) $config['secret_key']),
            'cdn' => trim((string) ($config['cdn'] ?? '')),
            'prefix' => self::prefix($config),
        ], static fn ($v) => $v !== ''));
    }
}
