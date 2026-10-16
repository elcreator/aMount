<?php

namespace Elcreator\aMount\Support;

/**
 * Translation that never throws and never answers with a bare key.
 *
 * A worker, a console command or a unit test has no booted container, so the
 * CMS translator may be missing, or may hand the key straight back. Text that
 * is produced outside a request (a run's error, a queue hint, an exception
 * message) goes through here: the translator when it works, otherwise the
 * English source file read directly, otherwise the key itself.
 */
final class Lang
{
    private const PREFIX = 'aMount::global.';

    /** @var array<string, string>|null */
    private static ?array $english = null;

    /**
     * @param string $key either `aMount::global.name` or just `name`
     * @param array<string, scalar> $replace placeholder name => value
     */
    public static function get(string $key, array $replace = []): string
    {
        $full = str_contains($key, '::') ? $key : self::PREFIX . $key;

        try {
            $text = (string) __($full, $replace);

            if ($text !== '' && $text !== $full) {
                return $text;
            }
        } catch (\Throwable) {
            // No translator in this process: fall through to the English file.
        }

        $name = str_starts_with($full, self::PREFIX) ? substr($full, strlen(self::PREFIX)) : null;
        $source = $name !== null ? (self::english()[$name] ?? null) : null;

        if ($source === null) {
            return $full;
        }

        foreach ($replace as $placeholder => $value) {
            $source = str_replace(':' . $placeholder, is_scalar($value) ? (string) $value : '', $source);
        }

        return $source;
    }

    /** @return array<string, string> */
    private static function english(): array
    {
        if (self::$english === null) {
            $file = dirname(__DIR__, 2) . '/lang/en/global.php';
            $rows = is_file($file) ? require $file : [];
            self::$english = is_array($rows) ? $rows : [];
        }

        return self::$english;
    }
}
