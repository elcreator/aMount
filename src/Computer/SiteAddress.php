<?php

namespace Elcreator\aMount\Computer;

use Elcreator\aMount\Support\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Which domains this installation serves, its public address, and its name - what a computer
 * is told about the site and what an agent's "upload it to site.com" is checked against.
 *
 * Evo's own `site_url` is the request's host in a web request but `http://localhost/` (or the
 * first EVO_SITE_HOSTNAMES entry, always http) in the CLI worker that runs transfers, so it is
 * never trusted there. In order:
 *  1. `cms.settings.aMount.computers.public_url` (an explicit address, or a map site key => URL);
 *  2. sMultisite: `server_protocol://domain` of the site's row (what sMultisite itself uses);
 *  3. the address seen in a web request (an MCP call, the aMount page) - only when that host is
 *     one this installation declares (EVO_SITE_HOSTNAMES, sMultisite, the pinned EVO_SITE_URL),
 *     or nothing is declared at all - remembered in `amount_state` for the worker;
 *  4. EVO_SITE_URL outside the CLI, or pinned to a real host.
 * No address: the computer is simply not offered this site's mailbox, and the relay carries it.
 */
final class SiteAddress
{
    private const SEEN = 'computer_site_url';

    /** @return array<int, array{key: string, domain: string, name: string, url: string}> */
    public static function sites(): array
    {
        $out = [];
        $protocol = self::protocol();

        foreach (self::multisiteRows() as $row) {
            $domain = self::host((string) $row->domain);
            if ($domain !== '') {
                $out[$domain] = ['key' => (string) $row->key, 'domain' => $domain, 'name' => (string) ($row->site_name ?: $domain),
                    'url' => self::configured((string) $row->key) ?? $protocol . '://' . $domain . '/'];
            }
        }

        foreach (self::declaredHosts() as $host) {
            $out[$host] ??= ['key' => 'default', 'domain' => $host, 'name' => self::siteName(),
                'url' => self::configured('default') ?? $protocol . '://' . $host . '/'];
        }

        return array_values($out);
    }

    /** The domains this installation serves, lower case, without scheme or default port. @return string[] */
    public static function domains(): array
    {
        return array_column(self::sites(), 'domain');
    }

    /** The site an agent named ("site.com", "https://www.site.com/"), or null when this installation does not serve it. */
    public static function find(string $named): ?array
    {
        $host = self::host($named);
        $sites = self::sites();
        foreach ([$host, preg_replace('/^www\./', '', $host), 'www.' . $host] as $try) {
            foreach ($sites as $site) {
                if ($site['domain'] === $try) {
                    return $site;
                }
            }
        }

        return null;
    }

    /** This site's public address for a computer to call, or null when it cannot be told. */
    public static function publicUrl(string $siteKey = 'default'): ?string
    {
        if (($url = self::configured($siteKey)) !== null) {
            return $url;
        }
        foreach (self::sites() as $site) {
            if ($site['key'] === $siteKey && !self::isLocal($site['domain'])) {
                return $site['url'];
            }
        }
        $seen = self::state(self::SEEN);
        if ($seen !== null) {
            return $seen;
        }
        $url = defined('EVO_SITE_URL') ? (string) EVO_SITE_URL : (string) (function_exists('evo') ? evo()->getConfig('site_url') : '');
        if ($url !== '' && !self::isLocal(self::host($url)) && (PHP_SAPI !== 'cli' || self::declaredHosts() !== [])) {
            return self::withProtocol($url);
        }

        return null;
    }

    /**
     * Remember the address of a web request (the aMount page, an MCP call) for the worker -
     * only a host this installation declares, or any when it declares none.
     */
    public static function remember(string $requestUrl): void
    {
        $host = self::host($requestUrl);
        if ($host === '' || self::isLocal($host)) {
            return;
        }
        $declared = array_merge(self::declaredHosts(), array_map(static fn ($r) => self::host((string) $r->domain), self::multisiteRows()));
        if ($declared !== [] && !in_array($host, $declared, true)) {
            return;
        }
        $url = self::withProtocol((string) preg_replace('#^(https?://[^/]+).*$#i', '$1/', $requestUrl));
        try {
            DB::table('amount_state')->updateOrInsert(['key' => self::SEEN], ['value' => $url, 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable) {
        }
    }

    public static function siteName(): string
    {
        $name = function_exists('evo') ? (string) evo()->getConfig('site_name') : '';

        return $name !== '' ? $name : (self::declaredHosts()[0] ?? 'Evolution CMS site');
    }

    /** A display name a computer shows its person: the domain, then the site's own name. */
    public static function label(): string
    {
        $url = self::publicUrl();
        $host = $url !== null ? self::host($url) : '';

        return $host !== '' ? $host . ' (' . self::siteName() . ')' : self::siteName();
    }

    // ------------------------------------------------------------------

    private static function configured(string $key): ?string
    {
        $value = Config::get('computers.public_url');
        if (is_array($value)) {
            $value = $value[$key] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? rtrim(trim($value), '/') . '/' : null;
    }

    /** @return string[] EVO_SITE_HOSTNAMES and a pinned EVO_SITE_URL's host */
    private static function declaredHosts(): array
    {
        $hosts = [];
        $list = defined('EVO_SITE_HOSTNAMES') ? (string) EVO_SITE_HOSTNAMES : (string) getenv('EVO_SITE_HOSTNAMES');
        foreach (explode(',', $list) as $h) {
            if (($h = self::host($h)) !== '' && !self::isLocal($h)) {
                $hosts[] = $h;
            }
        }
        if (defined('EVO_SITE_URL') && PHP_SAPI !== 'cli' && ($h = self::host((string) EVO_SITE_URL)) !== '' && !self::isLocal($h)) {
            $hosts[] = $h;
        }

        return array_values(array_unique($hosts));
    }

    /** @return array<int, object> active sMultisite rows, when the package is there */
    private static function multisiteRows(): array
    {
        try {
            if (!class_exists(\Seiger\sMultisite\Models\sMultisite::class) || !Schema::hasTable('s_multisites')) {
                return [];
            }

            return DB::table('s_multisites')->where('active', 1)->get(['key', 'domain', 'site_name'])->all();
        } catch (Throwable) {
            return [];
        }
    }

    private static function protocol(): string
    {
        $p = function_exists('evo') ? (string) evo()->getConfig('server_protocol') : '';

        return $p === 'http' ? 'http' : 'https';
    }

    private static function withProtocol(string $url): string
    {
        return self::protocol() === 'https' ? (string) preg_replace('#^http://#i', 'https://', $url) : $url;
    }

    /** "https://WWW.Site.com:443/x" -> "www.site.com". */
    public static function host(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $host = parse_url(str_contains($value, '://') ? $value : 'http://' . $value, PHP_URL_HOST);
        $port = parse_url(str_contains($value, '://') ? $value : 'http://' . $value, PHP_URL_PORT);
        $host = strtolower(trim((string) $host, '.'));

        return $host === '' ? '' : $host . ($port !== null && !in_array($port, [80, 443], true) ? ':' . $port : '');
    }

    private static function isLocal(string $host): bool
    {
        return $host === '' || in_array(preg_replace('/:\d+$/', '', $host), ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function state(string $key): ?string
    {
        try {
            $v = DB::table('amount_state')->where('key', $key)->value('value');

            return is_string($v) && $v !== '' ? $v : null;
        } catch (Throwable) {
            return null;
        }
    }
}
