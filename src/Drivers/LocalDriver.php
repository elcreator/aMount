<?php

namespace Elcreator\aMount\Drivers;

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;

/**
 * A folder on this machine: an NFS/SMB share or a second disk mounted into the
 * filesystem. Also the zero-credential driver for trying the module out.
 */
final class LocalDriver extends Driver
{
    public function id(): string
    {
        return 'local';
    }

    public function label(): string
    {
        return 'Local / network folder';
    }

    public function group(): string
    {
        return self::GROUP_SERVER;
    }

    public function packages(): array
    {
        return ['league/flysystem-local'];
    }

    protected function probeClass(): string
    {
        return LocalFilesystemAdapter::class;
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
            self::text('root', true, ['help' => 'aMount::global.h_local_root']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        return new LocalFilesystemAdapter(
            rtrim(str_replace('\\', '/', (string) $config['root']), '/'),
            PortableVisibilityConverter::fromArray([
                'file' => ['public' => 0644, 'private' => 0600],
                'dir' => ['public' => 0755, 'private' => 0700],
            ])
        );
    }
}
