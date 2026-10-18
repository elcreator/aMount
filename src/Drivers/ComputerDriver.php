<?php

namespace Elcreator\aMount\Drivers;

use Elcreator\aMount\Computer\Adapter;
use Elcreator\aMount\Computer\Client;
use Elcreator\aMount\Computer\Gateway;
use Elcreator\aMount\Computer\KeySource;
use Elcreator\aMount\Computer\X25519;
use League\Flysystem\FilesystemAdapter;
use RuntimeException;

/**
 * "My computer": the folders a person shares from their own computer with the ai.artur.work
 * program (mesh-daemon) - Windows, macOS or Linux, behind any NAT
 * (ai.artur.work docs/features/29-computer-files.md).
 *
 * Nothing on the computer is reachable but what its person shared, and only after they
 * approved this site (they compare this site's code, shown on the aMount page). Bytes are
 * sealed end to end: the gateway in between can neither read nor forge them. The path is
 * picked per transfer: straight to the computer on the same network, the computer calling
 * this site's own mailbox, the owner's own relay, or the gateway's relay.
 *
 * The API key: this mount's own, else the owner's (or the site's) aIMage key, else
 * `cms.settings.aMount.computers.api_key`. It needs "Shared folders on your computers"
 * switched on for it on the ai.artur.work profile.
 */
final class ComputerDriver extends Driver
{
    /** Tests replace the gateway (fn (string $key, string $url): Gateway). */
    public static ?\Closure $gatewayFactory = null;

    public function id(): string
    {
        return 'ai-computer';
    }

    public function label(): string
    {
        return 'My computer (ai.artur.work)';
    }

    public function packages(): array
    {
        // Already in every Evolution CMS core; the seal needs ext-sodium or PHP 8.4's OpenSSL.
        return ['guzzlehttp/guzzle'];
    }

    protected function probeClass(): string
    {
        return \GuzzleHttp\Client::class;
    }

    public function available(): bool
    {
        return parent::available() && X25519::available();
    }

    public function installCommand(): string
    {
        return X25519::available() ? parent::installCommand() : 'Enable the sodium PHP extension (or use PHP 8.4 or newer)';
    }

    /** The computer writes through a temporary name: a refused write leaves nothing to clean up. */
    public function writesAtomically(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            self::text('computer', true, ['help' => 'aMount::global.h_computer']),
            self::text('share', false, ['help' => 'aMount::global.h_computer_share']),
            self::secret('api_key', false, ['help' => 'aMount::global.h_computer_api_key']),
            self::text('gateway', false, ['default' => 'https://ai.artur.work/api/v1']),
            self::toggle('direct', true, ['help' => 'aMount::global.h_computer_direct']),
            self::toggle('site_mailbox', true, ['help' => 'aMount::global.h_computer_site_mailbox']),
            self::text('relay_url', false, ['help' => 'aMount::global.h_computer_relay_url']),
            self::number('site_quota_mb', 10240, ['help' => 'aMount::global.h_computer_site_quota_mb']),
        ];
    }

    protected function adapter(array $config, array $options): FilesystemAdapter
    {
        $owner = isset($options['_owner_id']) ? (int) $options['_owner_id'] : null;
        $key = KeySource::resolve((string) ($config['api_key'] ?? ''), $owner);
        if ($key === null) {
            throw new RuntimeException('No ai.artur.work API key: give this mount one, connect aIMage, or set cms.settings.aMount.computers.api_key.');
        }
        $url = trim((string) ($config['gateway'] ?? '')) ?: 'https://ai.artur.work/api/v1';
        $gateway = self::$gatewayFactory !== null ? (self::$gatewayFactory)($key, $url) : new Gateway($key, $url);
        $client = new Client($gateway, trim((string) $config['computer']), [
            'relay_url' => trim((string) ($config['relay_url'] ?? '')) ?: null,
            'site_mailbox' => filter_var($config['site_mailbox'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'direct' => filter_var($config['direct'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ]);

        return new Adapter($client, trim((string) ($config['share'] ?? ''), '/'));
    }

    /** The quota of a site folder files from this computer may fill, in bytes. Always set. */
    public static function siteQuota(array $config): int
    {
        $mb = (int) ($config['site_quota_mb'] ?? 10240);

        return max(1, $mb > 0 ? $mb : 10240) * 1024 * 1024;
    }
}
