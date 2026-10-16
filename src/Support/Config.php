<?php

namespace Elcreator\aMount\Support;

/**
 * Typed access to `cms.settings.aMount`.
 */
final class Config
{
    public static function get(string $key, $default = null)
    {
        return config('cms.settings.aMount.' . $key, $default);
    }

    public static function isEnabled(): bool
    {
        return (bool) static::get('enable', true);
    }

    public static function limit(string $key, $default = 0)
    {
        return static::get('limits.' . $key, $default);
    }

    public static function queueDriver(): string
    {
        $driver = strtolower(trim((string) static::get('queue.driver', 'auto')));

        return in_array($driver, ['auto', 'system', 'stask'], true) ? $driver : 'auto';
    }

    public static function serveEnabled(): bool
    {
        return (bool) static::get('serve.enabled', true);
    }

    public static function serveMode(): string
    {
        $mode = strtolower(trim((string) static::get('serve.mode', 'auto')));

        return in_array($mode, ['auto', 'redirect', 'proxy'], true) ? $mode : 'auto';
    }

    public static function includeDir(): string
    {
        $dir = trim((string) static::get('server.include_dir', ''));

        if ($dir !== '') {
            return rtrim(str_replace('\\', '/', $dir), '/');
        }

        return rtrim(str_replace('\\', '/', defined('EVO_CORE_PATH') ? EVO_CORE_PATH : sys_get_temp_dir()), '/') . '/custom/amount';
    }
}
