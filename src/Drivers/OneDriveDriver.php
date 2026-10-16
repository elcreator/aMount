<?php

namespace Elcreator\aMount\Drivers;

use Justus\FlysystemOneDrive\OneDriveAdapter;
use League\Flysystem\FilesystemAdapter;
use Microsoft\Graph\Graph;

/**
 * Microsoft OneDrive (personal, business, SharePoint document libraries) over
 * Microsoft Graph, with a refresh token from an Entra ID app registration.
 */
final class OneDriveDriver extends Driver
{
    public function id(): string
    {
        return 'onedrive';
    }

    public function label(): string
    {
        return 'Microsoft OneDrive';
    }

    public function group(): string
    {
        return self::GROUP_CLOUD;
    }

    public function packages(): array
    {
        return ['justus/flysystem-onedrive'];
    }

    protected function probeClass(): string
    {
        return OneDriveAdapter::class;
    }

    public function fields(): array
    {
        return [
            self::text('tenant', true, ['default' => 'common', 'help' => 'aMount::global.h_onedrive_tenant']),
            self::text('client_id', true),
            self::secret('client_secret', true),
            self::secret('refresh_token', true),
            self::text('drive_id', false, ['help' => 'aMount::global.h_onedrive_drive']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $tenant = trim((string) ($config['tenant'] ?? '')) ?: 'common';
        $token = OAuthToken::get(
            $config['client_id'] . '|' . $config['refresh_token'],
            'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => trim((string) $config['refresh_token']),
                'client_id' => trim((string) $config['client_id']),
                'client_secret' => trim((string) $config['client_secret']),
                'scope' => 'https://graph.microsoft.com/.default offline_access',
            ]
        );

        $graph = new Graph();
        $graph->setAccessToken($token);

        $driveId = trim((string) ($config['drive_id'] ?? ''));

        // /me/drive/root, or /drives/{id}/root for a named drive or library.
        return $driveId === ''
            ? new OneDriveAdapter($graph, 'drive', ['directory_type' => 'me'])
            : new OneDriveAdapter($graph, $driveId, ['directory_type' => 'drives']);
    }
}
