<?php

namespace Elcreator\aMount\Drivers;

use League\Flysystem\FilesystemAdapter;
use Overtrue\Flysystem\Qiniu\QiniuAdapter;

final class QiniuDriver extends Driver
{
    public function id(): string
    {
        return 'qiniu';
    }

    public function label(): string
    {
        return 'Qiniu Kodo';
    }

    public function group(): string
    {
        return self::GROUP_OBJECT;
    }

    public function packages(): array
    {
        return ['overtrue/flysystem-qiniu'];
    }

    protected function probeClass(): string
    {
        return QiniuAdapter::class;
    }

    public function needsPublicUrl(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            self::text('access_key', true),
            self::secret('secret_key', true),
            self::text('bucket', true),
            self::text('domain', true, ['help' => 'aMount::global.h_qiniu_domain']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        return new QiniuAdapter(
            trim((string) $config['access_key']),
            trim((string) $config['secret_key']),
            trim((string) $config['bucket']),
            trim((string) $config['domain'])
        );
    }
}
