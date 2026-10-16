<?php

namespace Elcreator\aMount\Drivers;

use Google\Client as GoogleClient;
use Google\Service\Drive;
use League\Flysystem\FilesystemAdapter;
use Masbug\Flysystem\GoogleDriveAdapter;

/**
 * Google Drive — a personal account (OAuth refresh token) or a service account
 * with a shared folder or shared drive.
 *
 * The usual home for this driver is a *personal* mount: a manager connects
 * their own Drive and sends the folders they can see to it.
 */
final class GoogleDriveDriver extends Driver
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function id(): string
    {
        return 'gdrive';
    }

    public function label(): string
    {
        return 'Google Drive';
    }

    public function group(): string
    {
        return self::GROUP_CLOUD;
    }

    public function packages(): array
    {
        return ['masbug/flysystem-google-drive-ext'];
    }

    protected function probeClass(): string
    {
        return GoogleDriveAdapter::class;
    }

    public function fields(): array
    {
        return [
            self::select('auth', ['oauth' => 'OAuth (a personal account)', 'service' => 'Service account'], 'oauth'),
            self::text('client_id', false, ['show' => ['auth' => 'oauth'], 'help' => 'aMount::global.h_gdrive_oauth']),
            self::secret('client_secret', false, ['show' => ['auth' => 'oauth']]),
            self::secret('refresh_token', false, ['show' => ['auth' => 'oauth']]),
            self::area('service_account_json', false, ['show' => ['auth' => 'service'], 'help' => 'aMount::global.h_gdrive_service']),
            self::text('folder', false, ['help' => 'aMount::global.h_gdrive_folder']),
            self::text('team_drive_id', false, ['help' => 'aMount::global.h_gdrive_team']),
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (($config['auth'] ?? 'oauth') === 'service') {
            if (json_decode((string) ($config['service_account_json'] ?? ''), true) === null) {
                $errors['service_account_json'] = 'aMount::global.error_field_required';
            }

            return $errors;
        }

        foreach (['client_id', 'client_secret', 'refresh_token'] as $field) {
            if (trim((string) ($config[$field] ?? '')) === '') {
                $errors[$field] = 'aMount::global.error_field_required';
            }
        }

        return $errors;
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $client = new GoogleClient();
        $client->setApplicationName('aMount');

        if (($config['auth'] ?? 'oauth') === 'service') {
            $client->setAuthConfig(json_decode((string) $config['service_account_json'], true));
            $client->setScopes([Drive::DRIVE]);
        } else {
            $token = OAuthToken::get(
                $config['client_id'] . '|' . $config['refresh_token'],
                self::TOKEN_URL,
                [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => trim((string) $config['refresh_token']),
                    'client_id' => trim((string) $config['client_id']),
                    'client_secret' => trim((string) $config['client_secret']),
                ]
            );

            $client->setAccessToken(['access_token' => $token, 'expires_in' => 3000, 'created' => time()]);
        }

        $settings = [];

        if (trim((string) ($config['team_drive_id'] ?? '')) !== '') {
            $settings['teamDriveId'] = trim((string) $config['team_drive_id']);
        }

        $folder = trim((string) ($config['folder'] ?? ''), '/');

        return new GoogleDriveAdapter(new Drive($client), $folder !== '' ? $folder . '/' : null, $settings);
    }
}
