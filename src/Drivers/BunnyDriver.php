<?php

namespace Elcreator\aMount\Drivers;

use League\Flysystem\FilesystemAdapter;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNAdapter;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNClient;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNRegion;

final class BunnyDriver extends Driver
{
    public function id(): string
    {
        return 'bunny';
    }

    public function label(): string
    {
        return 'Bunny.net Storage';
    }

    public function group(): string
    {
        return self::GROUP_OBJECT;
    }

    public function packages(): array
    {
        return ['platformcommunity/flysystem-bunnycdn'];
    }

    protected function probeClass(): string
    {
        return BunnyCDNAdapter::class;
    }

    public function needsPublicUrl(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            self::text('storage_zone', true),
            self::secret('api_key', true, ['help' => 'aMount::global.h_bunny_key']),
            self::select('region', [
                'de' => 'Falkenstein (DE)', 'uk' => 'London (UK)', 'se' => 'Stockholm (SE)', 'ny' => 'New York (US)',
                'la' => 'Los Angeles (US)', 'sg' => 'Singapore (SG)', 'syd' => 'Sydney (AU)', 'br' => 'Brazil (BR)', 'jh' => 'Johannesburg (ZA)',
            ], 'de'),
            self::text('pullzone_url', false, ['help' => 'aMount::global.h_bunny_pullzone']),
            self::text('prefix', false, ['help' => 'aMount::global.h_prefix']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        return new BunnyCDNAdapter(
            new BunnyCDNClient(trim((string) $config['storage_zone']), trim((string) $config['api_key']), (string) ($config['region'] ?? 'de')),
            trim((string) ($config['pullzone_url'] ?? '')),
            '/' . self::prefix($config)
        );
    }
}
