<?php

namespace Elcreator\aMount\Drivers;

use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use League\Flysystem\FilesystemAdapter;

/**
 * Azure Blob Storage through azure-oss/storage-blob-flysystem, the maintained
 * successor of the abandoned league/flysystem-azure-blob-storage.
 */
final class AzureDriver extends Driver
{
    public function id(): string
    {
        return 'azure';
    }

    public function label(): string
    {
        return 'Azure Blob Storage';
    }

    public function group(): string
    {
        return self::GROUP_OBJECT;
    }

    public function packages(): array
    {
        return ['azure-oss/storage-blob-flysystem'];
    }

    protected function probeClass(): string
    {
        return AzureBlobStorageAdapter::class;
    }

    public function fields(): array
    {
        return [
            self::area('connection_string', true, ['help' => 'aMount::global.h_azure_connection']),
            self::text('container', true),
            self::text('prefix', false, ['help' => 'aMount::global.h_prefix']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $container = BlobServiceClient::fromConnectionString(trim((string) $config['connection_string']))
            ->getContainerClient(trim((string) $config['container']));

        // Azure has no per-blob ACL: visibility calls are ignored rather than thrown.
        return new AzureBlobStorageAdapter(
            $container,
            self::prefix($config),
            null,
            AzureBlobStorageAdapter::ON_VISIBILITY_IGNORE
        );
    }
}
