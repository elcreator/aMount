<?php

namespace Elcreator\aMount\Services;

use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Support\Config;
use Elcreator\aMount\Support\Paths;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Web-server integration: how an offloaded public file keeps its URL once the
 * local copy is gone.
 *
 * The CMS can only answer for a missing file if the web server hands the
 * request over instead of answering 404 itself. nginx and Apache each shipped
 * rules that do not (see the docblocks of `render()`); Caddy/FrankenPHP already
 * does. This class generates the small pieces that change that, applies them
 * where it safely can, and detects when the public rules have outgrown what was
 * applied (drift).
 *
 * Nothing here is ever required for private files: those are not served by the
 * web server at all.
 */
final class ServerConfig
{
    public const SERVERS = ['nginx', 'apache', 'frankenphp'];

    public const BEGIN = '# BEGIN aMount';
    public const END = '# END aMount';

    /** The line the managed block goes in front of: it stops friendly URLs for /assets. */
    public const ANCHOR = 'RewriteRule ^(manager|assets|js|css|images|img)/.*$ - [L]';

    /** Top-level folders that are never worth (or safe) handing to the CMS. */
    private const SKIP = ['core', 'manager', 'vendor', 'views', 'tmp', 'temp', 'install', 'storage'];

    /** For tests: replaces the HTTP call of {@see probe()}. fn(string $url): array{status:int, header:string} */
    public static ?\Closure $fetcher = null;

    public static function detect(): string
    {
        $forced = strtolower(trim((string) Config::get('server.type', '')));

        if (in_array($forced, self::SERVERS, true)) {
            return $forced;
        }

        $software = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));

        if (str_contains($software, 'nginx')) {
            return 'nginx';
        }

        if (str_contains($software, 'frankenphp') || str_contains($software, 'caddy') || PHP_SAPI === 'frankenphp') {
            return 'frankenphp';
        }

        if (str_contains($software, 'apache') || str_contains($software, 'litespeed')) {
            return 'apache';
        }

        return 'unknown';
    }

    /**
     * Top-level directories of every enabled public rule.
     *
     * @return string[]
     */
    public static function directories(): array
    {
        $dirs = [];

        foreach (Rule::query()->where('enabled', true)->where('visibility', Rule::PUBLIC)->pluck('local_path') as $path) {
            $path = (string) $path;

            // A rule on a single file in the site root has no folder to hand over.
            if (!str_contains($path, '/') && !is_dir(Paths::absolute($path))) {
                continue;
            }

            $top = explode('/', $path, 2)[0];

            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $top) && !in_array(strtolower($top), self::SKIP, true)) {
                $dirs[$top] = true;
            }
        }

        $dirs = array_keys($dirs);
        sort($dirs, SORT_STRING);

        return $dirs;
    }

    /** @param string[] $dirs */
    public static function render(string $server, array $dirs): string
    {
        $dirs = array_values(array_filter($dirs, static fn ($d) => (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', (string) $d)));
        sort($dirs, SORT_STRING);

        return match ($server) {
            'nginx' => self::nginx($dirs),
            'apache' => self::apache($dirs),
            'frankenphp' => self::caddy(),
            default => throw AmountException::invalid('error_server_unknown'),
        };
    }

    /** @return array<string, mixed> */
    public static function status(): array
    {
        $server = self::detect();
        $dirs = self::directories();
        $current = self::fingerprint($server, $dirs);
        $applied = self::applied();
        $needsChange = $server !== 'frankenphp' && $server !== 'unknown';

        $path = $needsChange ? self::targetPath($server) : null;

        return [
            'server' => $server,
            'supported' => in_array($server, self::SERVERS, true),
            'directories' => $dirs,
            'fingerprint' => $current,
            'applied' => $applied['fingerprint'] ?? null,
            'applied_server' => $applied['server'] ?? null,
            'drift' => $needsChange && $current !== ($applied['fingerprint'] ?? self::fingerprint($server, [])),
            'needs_change' => $needsChange,
            'include_path' => $path,
            'writable' => $path !== null && self::writable($server),
            'instructions' => AmountException::text('aMount::global.server_instr_' . ($server === 'unknown' ? 'unknown' : $server), ['path' => (string) $path]),
        ];
    }

    /**
     * Generate and apply the integration for $server.
     *
     * @return array{server:string, path:string, changed:bool, directories:string[]}
     * @throws AmountException
     */
    public static function write(string $server): array
    {
        if (!in_array($server, self::SERVERS, true)) {
            throw AmountException::invalid('error_server_unknown');
        }

        $dirs = self::directories();
        $path = self::targetPath($server);
        $changed = $server === 'apache' ? self::writeHtaccess($path, $dirs) : self::writeFile($path, self::render($server, $dirs));

        self::remember($server, $dirs);

        return ['server' => $server, 'path' => $path, 'changed' => $changed, 'directories' => $dirs];
    }

    /**
     * Called whenever the public rules change. Only keeps up what an
     * administrator already applied: it never edits `.htaccess` on its own
     * initiative, and it never throws into the request that saved a rule.
     */
    public static function refresh(): void
    {
        try {
            $applied = self::applied();
            $server = $applied['server'] ?? null;

            if ($server === null || !in_array($server, self::SERVERS, true) || !self::writable($server)) {
                return;
            }

            self::write($server);
        } catch (Throwable) {
        }
    }

    /**
     * Ask the site whether the web server forwards a missing file under $dir to
     * the CMS: the request is answered by the NotFound listener with a marker.
     *
     * @return array{ok:bool, dir:string, url:string, status:int, message:string}
     */
    public static function probe(string $dir): array
    {
        $dir = Paths::clean($dir);

        if ($dir === null || $dir === '') {
            throw AmountException::invalid('error_path_invalid');
        }

        $base = rtrim((string) evo()->getConfig('site_url', ''), '/');
        $url = $base . '/' . $dir . '/amount-probe-' . self::probeToken() . '.txt';

        try {
            $result = (self::$fetcher ?? static function (string $url): array {
                $response = (new \GuzzleHttp\Client(['timeout' => 4, 'connect_timeout' => 3, 'http_errors' => false, 'allow_redirects' => false]))->get($url);

                return ['status' => $response->getStatusCode(), 'header' => $response->getHeaderLine('X-aMount')];
            })($url);
        } catch (Throwable $e) {
            return ['ok' => false, 'dir' => $dir, 'url' => $url, 'status' => 0, 'message' => AmountException::text('aMount::global.probe_unreachable')];
        }

        $ok = $result['status'] === 200 && $result['header'] === 'ok';

        return [
            'ok' => $ok,
            'dir' => $dir,
            'url' => $url,
            'status' => (int) $result['status'],
            'message' => AmountException::text($ok ? 'aMount::global.probe_ok' : 'aMount::global.probe_blocked', ['dir' => $dir, 'status' => $result['status']]),
        ];
    }

    /** The secret the probe URL carries; created on first need unless $create is false. */
    public static function probeToken(bool $create = true): ?string
    {
        try {
            $token = DB::table('amount_state')->where('key', 'probe_token')->value('value');

            if (is_string($token) && $token !== '') {
                return $token;
            }

            if (!$create) {
                return null;
            }

            $token = bin2hex(random_bytes(12));
            self::put('probe_token', $token);

            return $token;
        } catch (Throwable) {
            return null;
        }
    }

    public static function targetPath(string $server): string
    {
        return match ($server) {
            'nginx' => Config::includeDir() . '/amount.nginx.conf',
            'frankenphp' => Config::includeDir() . '/amount.caddy',
            default => Paths::base() . '/.htaccess',
        };
    }

    // ------------------------------------------------------------------
    // Renderers
    // ------------------------------------------------------------------

    /** @param string[] $dirs */
    private static function alternatives(array $dirs): string
    {
        return implode('|', array_map(static function (string $dir) {
            $quoted = preg_quote($dir, '/');

            return $dir === 'assets' ? 'assets/(?!cache/|backup/|export/|import/)' : $quoted . '/';
        }, $dirs));
    }

    /**
     * nginx: the static-files regex in `ng.inx` answers 404 for a missing image
     * or archive before the CMS is asked, so these folders get their own
     * location that falls through to `@rewrite`. It has to be included after the
     * deny rules and before the static-files regex.
     */
    private static function nginx(array $dirs): string
    {
        if ($dirs === []) {
            return "# aMount: no public offloaded folders yet - nothing to add.\n";
        }

        return "# Managed by aMount - regenerate with: php artisan amount:server nginx --write\n"
            . "# Include after the deny rules and before the static-files location of ng.inx.\n"
            . 'location ~ ^/(' . self::alternatives($dirs) . ") {\n"
            . "    try_files \$uri @rewrite;\n"
            . "    expires max;\n"
            . "    access_log off;\n"
            . "}\n";
    }

    /** Apache: `ht.access` stops friendly URLs for /assets; ask the CMS for missing files first. */
    private static function apache(array $dirs): string
    {
        if ($dirs === []) {
            return '';
        }

        return self::BEGIN . "\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . 'RewriteRule ^(' . self::alternatives($dirs) . ').+$ index.php?q=$0 [L,QSA]' . "\n"
            . self::END . "\n";
    }

    private static function caddy(): string
    {
        return "# aMount: nothing to change.\n"
            . "# The Salo/FrankenPHP Caddyfile already rewrites every missing path to the CMS\n"
            . "# (@notfile + rewrite), so offloaded public files keep their URLs.\n";
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    private static function writeFile(string $path, string $content): bool
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw AmountException::invalid('error_server_write');
        }

        if (is_file($path) && file_get_contents($path) === $content) {
            return false;
        }

        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw AmountException::invalid('error_server_write');
        }

        return true;
    }

    /** @param string[] $dirs */
    private static function writeHtaccess(string $path, array $dirs): bool
    {
        if (!is_file($path)) {
            throw AmountException::invalid('error_server_htaccess_missing');
        }

        $current = (string) file_get_contents($path);
        $updated = self::applyBlock($current, self::render('apache', $dirs));

        if ($updated === null) {
            throw AmountException::invalid('error_server_htaccess_anchor');
        }

        if ($updated === $current) {
            return false;
        }

        if (@file_put_contents($path . '.amount.bak', $current) === false || @file_put_contents($path, $updated, LOCK_EX) === false) {
            throw AmountException::invalid('error_server_write');
        }

        return true;
    }

    /**
     * Put $block in front of the anchor line, replacing any earlier managed
     * block (an empty $block removes it). Null when the anchor is missing.
     * Line endings of the file are kept.
     */
    public static function applyBlock(string $htaccess, string $block): ?string
    {
        $eol = str_contains($htaccess, "\r\n") ? "\r\n" : "\n";
        $stripped = (string) preg_replace('/^' . preg_quote(self::BEGIN, '/') . '\R.*?^' . preg_quote(self::END, '/') . '(\R|$)/ms', '', $htaccess);

        if ($block === '') {
            return $stripped;
        }

        $block = str_replace("\n", $eol, $block);
        $pattern = '/^[ \t]*' . preg_quote(self::ANCHOR, '/') . '[ \t]*(?=\r?$)/m';

        if (!preg_match($pattern, $stripped, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return substr($stripped, 0, $m[0][1]) . $block . substr($stripped, $m[0][1]);
    }

    private static function writable(string $server): bool
    {
        $path = self::targetPath($server);

        return $server === 'apache'
            ? is_file($path) && is_writable($path)
            : (is_dir(dirname($path)) ? is_writable(dirname($path)) : is_writable(dirname(dirname($path))));
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    /** @param string[] $dirs */
    private static function fingerprint(string $server, array $dirs): string
    {
        return sha1($server . '|' . implode(',', $dirs));
    }

    /** @return array{server?:string, fingerprint?:string} */
    private static function applied(): array
    {
        try {
            $raw = DB::table('amount_state')->where('key', 'server_applied')->value('value');
            $data = is_string($raw) ? json_decode($raw, true) : null;
        } catch (Throwable) {
            $data = null;
        }

        return is_array($data) ? $data : [];
    }

    /** @param string[] $dirs */
    private static function remember(string $server, array $dirs): void
    {
        self::put('server_applied', (string) json_encode(['server' => $server, 'fingerprint' => self::fingerprint($server, $dirs), 'directories' => $dirs]));
    }

    private static function put(string $key, string $value): void
    {
        DB::table('amount_state')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => Carbon::now()]);
    }
}
