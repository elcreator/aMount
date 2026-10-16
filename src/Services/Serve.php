<?php

namespace Elcreator\aMount\Services;

use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Support\Config;
use Elcreator\aMount\Support\Paths;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Answers requests for offloaded files.
 *
 * Two doors, deliberately kept apart:
 *  - {@see find()} / {@see publicResponse()}: anonymous visitors, via the
 *    OnPageNotFound listener. Only entries that are uploaded, public, whose rule
 *    is enabled and whose mount is not failing. Nothing else is ever reachable
 *    without a login.
 *  - {@see privateResponse()}: a signed-in manager who owns the rule (or
 *    administers rules) and could see the original file in the file manager.
 */
final class Serve
{
    /** Never answered from the store, whatever a rule says: source code and server config. */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'inc', 'cgi', 'pl', 'sh',
        'env', 'ini', 'htaccess', 'htpasswd', 'sql', 'log', 'bak',
    ];

    /** The offloaded public entry for a request path, or null. */
    public static function find(string $sitePath): ?FileEntry
    {
        if (!Config::serveEnabled()) {
            return null;
        }

        $path = Paths::clean($sitePath);

        if ($path === null || $path === '') {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, self::BLOCKED_EXTENSIONS, true) || str_starts_with(basename($path), '.')) {
            return null;
        }

        $candidates = FileEntry::query()
            ->where('path_hash', Paths::hash($path))
            ->where('status', FileEntry::UPLOADED)
            ->where('visibility', Rule::PUBLIC)
            ->orderByDesc('uploaded_at')->orderByDesc('id')
            ->get();

        foreach ($candidates as $entry) {
            // The hash is the key; the path comparison guards against a collision.
            if ($entry->local_path !== $path) {
                continue;
            }

            $rule = Rule::query()->find($entry->rule_id);
            $mount = Mount::query()->find($entry->mount_id);

            if ($rule === null || !$rule->enabled || !$rule->isPublic() || $mount === null || $mount->status === Mount::STATUS_ERROR) {
                continue;
            }

            return $entry;
        }

        return null;
    }

    /** Redirect to the store's public URL when there is one, otherwise stream through the site. */
    public static function publicResponse(FileEntry $entry, ?Request $request = null): Response
    {
        $request ??= Request::createFromGlobals();
        $mount = $entry->mount;

        if ($mount === null) {
            return new Response('', 404);
        }

        $cache = 'public, max-age=' . max(0, (int) Config::get('serve.cache_seconds', 3600));
        $mode = in_array($mount->option('serve'), ['redirect', 'proxy'], true) ? $mount->option('serve') : Config::serveMode();

        if ($mode !== 'proxy' && ($url = self::publicUrl($entry, $mount, $mode === 'redirect')) !== null) {
            $response = new RedirectResponse($url, 302);
            $response->headers->set('Cache-Control', $cache);

            return $response;
        }

        return self::stream($entry, $request, 'inline', $cache);
    }

    /**
     * A download for a signed-in manager.
     *
     * @throws AmountException when the actor may not have this file
     */
    public static function privateResponse(FileEntry $entry, Actor $actor, ?Request $request = null): Response
    {
        $request ??= Request::createFromGlobals();
        $actor->authorize();

        $rule = Rule::query()->find($entry->rule_id);
        $mount = $entry->mount;

        if ($rule === null || $mount === null || $entry->status !== FileEntry::UPLOADED) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_file'));
        }

        if ((int) $rule->owner_id !== $actor->userId() && !$actor->mayManage()) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_file'));
        }

        // The original must be visible to the downloader, as it would be in the file manager.
        $absolute = Paths::absolute($entry->local_path);
        $scope = $actor->scope();
        $directory = dirname($absolute);

        if (($scope->denyFile($absolute) !== null) || (is_dir($directory) && $scope->denyDirectory($directory) !== null)) {
            throw AmountException::forbidden();
        }

        if (!(new Mounts($actor))->canUse($mount)) {
            throw AmountException::forbidden();
        }

        try {
            $ttl = max(30, (int) Config::get('serve.temporary_url_ttl', 600));
            $url = $mount->filesystem()->temporaryUrl($entry->remote_path, new \DateTimeImmutable('+' . $ttl . ' seconds'));

            $response = new RedirectResponse($url, 302);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        } catch (Throwable) {
            // The adapter cannot sign URLs: stream it through the site instead.
        }

        return self::stream($entry, $request, 'attachment', 'private, no-store');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function publicUrl(FileEntry $entry, Mount $mount, bool $allowAdapter): ?string
    {
        if ($mount->publicUrl() !== '') {
            return $mount->publicUrl() . '/' . implode('/', array_map('rawurlencode', explode('/', trim($entry->remote_path, '/'))));
        }

        if (!$allowAdapter) {
            return null;
        }

        try {
            return $mount->filesystem()->publicUrl($entry->remote_path);
        } catch (Throwable) {
            return null;
        }
    }

    /** ETag, conditional requests, a single byte Range, and the body from the store. */
    private static function stream(FileEntry $entry, Request $request, string $disposition, string $cache): Response
    {
        $size = (int) $entry->size;
        $etag = '"' . dechex($size) . '-' . dechex((int) $entry->mtime) . '"';
        $headers = [
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', (int) $entry->mtime) . ' GMT',
            'Cache-Control' => $cache,
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $none = trim((string) $request->headers->get('If-None-Match', ''));

        if ($none !== '' && in_array($etag, array_map('trim', explode(',', str_replace('W/', '', $none))), true)) {
            return new Response('', 304, $headers);
        }

        $range = null;
        $ifRange = trim((string) $request->headers->get('If-Range', ''));

        if (($header = (string) $request->headers->get('Range', '')) !== '' && ($ifRange === '' || $ifRange === $etag)) {
            $range = self::range($header, $size);

            if ($range === false) {
                return new Response('', 416, $headers + ['Content-Range' => 'bytes */' . $size]);
            }
        }

        $name = basename($entry->local_path);
        $headers['Content-Type'] = (new ExtensionMimeTypeDetector())->detectMimeTypeFromPath($name) ?: 'application/octet-stream';
        $headers['Content-Disposition'] = $disposition . '; filename="' . addcslashes((string) preg_replace('/[^\x20-\x7e]/', '_', $name), '"\\')
            . '"; filename*=UTF-8\'\'' . rawurlencode($name);

        [$start, $end] = $range ?? [0, max(0, $size - 1)];
        $length = $size === 0 ? 0 : $end - $start + 1;
        $status = $range === null ? 200 : 206;
        $headers['Content-Length'] = (string) $length;

        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        if ($request->isMethod('HEAD')) {
            return new Response('', $status, $headers);
        }

        // Adapters may warn before they throw; a vanished object is a 404, not a notice in the log.
        set_error_handler(static fn () => true);

        try {
            $source = $entry->mount->filesystem()->readStream($entry->remote_path);
        } catch (Throwable) {
            return new Response('', 404);
        } finally {
            restore_error_handler();
        }

        return new StreamedResponse(static function () use ($source, $start, $length) {
            if ($start > 0 && @fseek($source, $start) !== 0) {
                // Not seekable (a remote stream): read and discard up to the offset.
                for ($skipped = 0; $skipped < $start && !feof($source);) {
                    $chunk = fread($source, (int) min(65536, $start - $skipped));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $skipped += strlen($chunk);
                }
            }

            for ($sent = 0; $sent < $length && !feof($source);) {
                $chunk = fread($source, (int) min(65536, $length - $sent));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                $sent += strlen($chunk);
                flush();
            }

            fclose($source);
        }, $status, $headers);
    }

    /**
     * One byte range. Null = no usable header (serve everything), false = not
     * satisfiable (416). Several ranges are not supported and ignored, which RFC 9110 allows.
     *
     * @return array{0:int,1:int}|false|null
     */
    public static function range(string $header, int $size): array|false|null
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/i', trim($header), $m) || ($m[1] === '' && $m[2] === '')) {
            return null;
        }

        if ($size <= 0) {
            return false;
        }

        if ($m[1] === '') {
            $length = (int) $m[2];

            return $length === 0 ? false : [max(0, $size - $length), $size - 1];
        }

        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);

        return $start >= $size || $end < $start ? false : [$start, $end];
    }
}
