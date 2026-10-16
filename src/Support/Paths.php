<?php

namespace Elcreator\aMount\Support;

use EvolutionCMS\Support\FileManagerAccess;

/**
 * Path arithmetic. Everything here is pure string work except `base()`.
 *
 * Two kinds of path travel through the package and must not be mixed up:
 *
 *  - a *site path*: relative to the site root, forward slashes, no edges
 *    (`assets/images/2026/a.jpg`). What rules store, what URLs are made of, and
 *    what the index is keyed by.
 *  - an *absolute path*: what the filesystem is asked about.
 */
final class Paths
{
    /** Absolute site root, forward slashes, no trailing slash. */
    public static function base(): string
    {
        $base = defined('EVO_BASE_PATH') ? EVO_BASE_PATH : getcwd();

        return rtrim(str_replace('\\', '/', realpath($base) ?: $base), '/');
    }

    /**
     * A clean site path, or null when it is not one: empty segments, `.`/`..`
     * and NUL bytes are refused outright rather than normalised away, because a
     * normaliser is a thing that can be wrong once and wrong forever.
     */
    public static function clean(?string $path): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $path), '/');

        if ($path === '') {
            return '';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                return null;
            }
        }

        return $path;
    }

    public static function absolute(string $sitePath): string
    {
        $sitePath = trim($sitePath, '/');

        return $sitePath === '' ? self::base() : self::base() . '/' . $sitePath;
    }

    /** The site path of an absolute one, or null when it lies outside the site. */
    public static function relative(string $absolute): ?string
    {
        $absolute = rtrim(str_replace('\\', '/', $absolute), '/');
        $base = self::base();

        if (!FileManagerAccess::isWithin($base, $absolute)) {
            $real = realpath($absolute);
            $absolute = $real === false ? $absolute : rtrim(str_replace('\\', '/', $real), '/');

            if (!FileManagerAccess::isWithin($base, $absolute)) {
                return null;
            }
        }

        return trim(substr($absolute, strlen($base)), '/');
    }

    /** Remote key of a file: prefix + the part of its site path below the rule's folder. */
    public static function remote(string $prefix, string $below): string
    {
        return trim(trim($prefix, '/') . '/' . trim($below, '/'), '/');
    }

    public static function hash(string $sitePath): string
    {
        return sha1($sitePath);
    }

    /**
     * Whether $relative (a path below the rule folder) passes include/exclude globs.
     *
     * A pattern with no slash matches the file name anywhere; one with a slash
     * matches the whole relative path. `*` stays inside a segment, `**` crosses.
     *
     * @param string[] $include empty = everything
     * @param string[] $exclude
     */
    public static function matches(string $relative, array $include, array $exclude): bool
    {
        foreach ($exclude as $pattern) {
            if (self::glob($pattern, $relative)) {
                return false;
            }
        }

        if ($include === []) {
            return true;
        }

        foreach ($include as $pattern) {
            if (self::glob($pattern, $relative)) {
                return true;
            }
        }

        return false;
    }

    public static function glob(string $pattern, string $relative): bool
    {
        $pattern = trim(str_replace('\\', '/', $pattern));

        if ($pattern === '') {
            return false;
        }

        $subject = str_contains($pattern, '/') ? $relative : basename($relative);
        $regex = '';

        for ($i = 0, $n = strlen($pattern); $i < $n; $i++) {
            $c = $pattern[$i];

            if ($c === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $regex .= '.*';
                    $i++;
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($c === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($c, '#');
            }
        }

        return (bool) preg_match('#^' . $regex . '$#iu', $subject);
    }

    /** A human size: 1.4 GB. */
    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $bytes = max(0, (float) $bytes);

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $bytes : number_format($bytes, 1)) . ' ' . $units[$i];
    }
}
