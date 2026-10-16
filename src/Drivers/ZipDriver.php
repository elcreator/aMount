<?php

namespace Elcreator\aMount\Drivers;

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\ZipArchive\FilesystemZipArchiveProvider;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;

/**
 * A ZIP archive as the destination: everything a rule sends ends up in one
 * file. Handy for a portable off-site bundle; not meant for huge sets, since a
 * zip has to be rewritten to be appended to.
 */
final class ZipDriver extends Driver
{
    public function id(): string
    {
        return 'zip';
    }

    public function label(): string
    {
        return 'ZIP archive';
    }

    public function group(): string
    {
        return self::GROUP_SERVER;
    }

    public function packages(): array
    {
        return ['league/flysystem-ziparchive'];
    }

    protected function probeClass(): string
    {
        return ZipArchiveAdapter::class;
    }

    public function available(): bool
    {
        return parent::available() && class_exists(\ZipArchive::class);
    }

    public function needsPublicUrl(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            self::text('path', true, ['help' => 'aMount::global.h_zip_path']),
            self::text('prefix', false, ['help' => 'aMount::global.h_prefix']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        return new ZipArchiveAdapter(new FilesystemZipArchiveProvider(str_replace('\\', '/', trim((string) $config['path']))), self::prefix($config));
    }
}
