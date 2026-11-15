<?php

namespace Elcreator\aMount\Drivers;

use Elcreator\aMount\Support\Lang;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use RuntimeException;

final class SftpDriver extends Driver
{
    public function id(): string
    {
        return 'sftp';
    }

    public function label(): string
    {
        return 'SFTP / SSH';
    }

    public function group(): string
    {
        return self::GROUP_SERVER;
    }

    public function packages(): array
    {
        return ['league/flysystem-sftp-v3'];
    }

    protected function probeClass(): string
    {
        return SftpAdapter::class;
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
            self::text('host', true),
            self::number('port', 22),
            self::text('username', true),
            self::secret('password'),
            self::area('private_key', false, ['help' => 'aMount::global.h_sftp_key']),
            self::secret('passphrase'),
            self::text('host_fingerprint', true, ['secret' => false, 'help' => 'aMount::global.h_sftp_fingerprint']),
            self::text('root', true, ['default' => '/', 'help' => 'aMount::global.h_sftp_root']),
        ];
    }

    public function validate(array $config): array
    {
        $errors = parent::validate($config);

        if (trim((string) ($config['password'] ?? '')) === '' && trim((string) ($config['private_key'] ?? '')) === '') {
            $errors['password'] = 'aMount::global.error_field_required';
        }

        if (trim((string) ($config['host_fingerprint'] ?? '')) !== '' && self::fingerprints((string) $config['host_fingerprint']) === []) {
            $errors['host_fingerprint'] = 'aMount::global.error_sftp_fingerprint';
        }

        return $errors;
    }

    public function destinations(array $config): array
    {
        return ['host' => trim((string) ($config['host'] ?? ''))];
    }

    /**
     * The pinned host keys: OpenSSH `SHA256:...` (what `ssh-keygen -lf` prints),
     * `md5:`/`sha1:`/`sha512:` prefixed, or colon/plain hex. Several may be
     * given, separated by spaces, commas or lines, so a key can be rotated by
     * pinning the new one before the server switches. Anything else is ignored,
     * which lets a pasted `ssh-keygen` line work as is.
     *
     * @return string[]
     */
    public static function fingerprints(string $value): array
    {
        $found = [];

        foreach (preg_split('/[\s,]+/', trim($value)) ?: [] as $token) {
            $token = trim($token, '()');

            if (preg_match('/^(md5|sha1|sha256|sha512):[A-Za-z0-9+\/=:]+$/i', $token) || preg_match('/^(?:[0-9a-f]{2}:?){16,64}$/i', $token)) {
                $found[] = $token;
            }
        }

        return array_values(array_unique($found));
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $key = trim((string) ($config['private_key'] ?? ''));
        $fingerprints = self::fingerprints((string) ($config['host_fingerprint'] ?? ''));

        // Without a pinned key anyone on the network path could pose as the server.
        if ($fingerprints === []) {
            throw new RuntimeException(Lang::get('aMount::global.error_sftp_fingerprint'));
        }

        // Hosts given by name are pinned like WebDAV, so the key check runs against the checked address.
        $host = trim((string) $config['host']);
        $host = $options['_pinned'][strtolower(trim($host, '[]'))] ?? $host;

        return new SftpAdapter(
            new SftpConnectionProvider(
                host: $host,
                username: trim((string) $config['username']),
                password: ($config['password'] ?? '') !== '' ? (string) $config['password'] : null,
                privateKey: $key !== '' ? $key : null,
                passphrase: ($config['passphrase'] ?? '') !== '' ? (string) $config['passphrase'] : null,
                port: (int) ($config['port'] ?? 22) ?: 22,
                timeout: 30,
                maxTries: 3,
                hostFingerprint: $fingerprints
            ),
            '/' . trim((string) ($config['root'] ?? '/'), '/')
        );
    }
}
