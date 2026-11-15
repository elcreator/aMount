<?php

namespace Elcreator\aMount\Support;

use Closure;

/**
 * Where a mount may connect when nobody trusted chose the address.
 *
 * A manager who is not an administrator types a WebDAV URL, an S3 endpoint or
 * an SFTP host; the server then connects from its own network position. Left
 * unchecked that reaches loopback services, the LAN and cloud metadata
 * endpoints (SSRF). Such a destination must resolve to public addresses only -
 * every address it resolves to, so one public record cannot smuggle in a
 * private one - and the caller connects to the address checked here.
 */
final class Destination
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const UNRESOLVED = 'unresolved';
    public const PRIVATE = 'private';

    /** Test seam: host => addresses. Null uses the system resolver. */
    public static ?Closure $resolver = null;

    /**
     * Host of a URL (`https://host/dav/`), a DSN-less `host:port` or a bare host.
     * Brackets of an IPv6 literal are removed; null when no host can be read.
     */
    public static function host(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || preg_match('/\s/', $value)) {
            return null;
        }

        $host = parse_url(str_contains($value, '://') ? $value : 'tcp://' . $value, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower(trim($host, '[]'));
    }

    /**
     * Every address the host resolves to. Both the system resolver (hosts file,
     * numeric forms such as `2130706433`) and DNS A/AAAA records are asked,
     * because the connecting library may use either.
     *
     * @return string[]
     */
    public static function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (self::$resolver !== null) {
            return array_values(array_unique(array_map('strval', (self::$resolver)($host))));
        }

        $found = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && $ip !== '') {
                $found[] = $ip;
            }
        }

        return array_values(array_unique($found));
    }

    /** Globally routable: not loopback, private, link-local, CGNAT, documentation, reserved ... */
    public static function isPublic(string $ip): bool
    {
        // ::ffff:127.0.0.1 is 127.0.0.1 to the socket.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) {
            $ip = $m[1];
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /**
     * The verdict for one configured value, with the address to connect to.
     *
     * @return array{status:string, host:?string, address:?string}
     */
    public static function inspect(string $value): array
    {
        $host = self::host($value);

        if ($host === null) {
            return ['status' => self::INVALID, 'host' => null, 'address' => null];
        }

        $addresses = self::addresses($host);

        if ($addresses === []) {
            return ['status' => self::UNRESOLVED, 'host' => $host, 'address' => null];
        }

        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                return ['status' => self::PRIVATE, 'host' => $host, 'address' => null];
            }
        }

        return ['status' => self::OK, 'host' => $host, 'address' => $addresses[0]];
    }
}
