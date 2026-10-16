<?php

/**
 * Test bootstrap.
 *
 * aMount has no vendor directory of its own — it is installed into an
 * Evolution CMS core and borrows that core's autoloader, which is also where
 * Pest, Eloquent and Flysystem come from. The suite points at a core with its
 * dev dependencies installed (`EVO_CORE_PATH_TEST`) and maps this package's
 * namespace on top.
 *
 * Everything the CMS would define at boot is stubbed here rather than booting
 * the CMS: these are unit tests against a real database and a real file tree,
 * not integration tests against a running site.
 */

$core = getenv('EVO_CORE_PATH_TEST') ?: 'C:/projects/Opensource/evo/evolution/core';
$core = rtrim(str_replace('\\', '/', $core), '/');

if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "aMount tests need an Evolution CMS core to borrow an autoloader from.\n"
        . "Looked in: {$core}\n"
        . "Set EVO_CORE_PATH_TEST to point at one.\n");
    exit(1);
}

require $core . '/vendor/autoload.php';

// This package's own namespace.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Elcreator\\aMount\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

/**
 * A file tree for the suite to treat as the site, and a second one for the
 * "remote" side of local-driver mounts. Fixed for the whole run because
 * EVO_BASE_PATH is a constant, and torn down at shutdown.
 */
$root = str_replace('\\', '/', sys_get_temp_dir()) . '/amount-tests-' . getmypid();
@mkdir($root . '/assets/files', 0775, true);
@mkdir($root . '/core/custom', 0775, true);
@mkdir($root . '/storage', 0775, true);

define('AMOUNT_TEST_ROOT', $root);
define('AMOUNT_TEST_REMOTE', $root . '-remote');
define('EVO_BASE_PATH', AMOUNT_TEST_ROOT . '/');
define('EVO_CORE_PATH', AMOUNT_TEST_ROOT . '/core/');
define('EVO_STORAGE_PATH', AMOUNT_TEST_ROOT . '/storage');

define('IN_MANAGER_MODE', false);
define('IN_INSTALL_MODE', false);
define('EVO_API_MODE', false);

// Credentials are encrypted at rest; pinning the secret keeps the suite from
// writing a real one into core/custom.
putenv('AMOUNT_SECRET=amount-test-secret');

/**
 * Stands in for `EvolutionCMS\Core` so the package's `evo()` and `config()`
 * calls resolve (`EVO_CLASS` is the documented seam for this).
 */
final class AmountTestCore
{
    public static ?AmountTestCore $instance = null;

    public static ?\Illuminate\Config\Repository $repository = null;

    /** Settings `evo()->getConfig()` answers with; tests may write to it. */
    public static array $config = [];

    public array $configGlobal = [];

    public static function defaults(): array
    {
        return [
            'use_udperms' => true,
            // The core resolves its ACL root from this; empty would make realpath('') mean "the cwd".
            'filemanager_path' => '[(base_path)]',
            'site_url' => 'https://example.test/',
        ];
    }

    public function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function make(string $abstract, array $parameters = [])
    {
        return match ($abstract) {
            'config' => self::config(),
            // Message text is not what these tests are about: the key is echoed back.
            'translator' => new class {
                public function get($key, array $replace = [], $locale = null)
                {
                    return (string) $key;
                }
            },
            default => null,
        };
    }

    public function getConfig(string $key, $default = null)
    {
        return self::$config[$key] ?? $default;
    }

    /** The manager session: tests set it to act as a user through the controllers. */
    public static int $loginUserId = 0;

    public function getLoginUserID(string $context = ''): int
    {
        return self::$loginUserId;
    }

    public static function config(): \Illuminate\Config\Repository
    {
        return self::$repository ??= new \Illuminate\Config\Repository([
            'cms' => ['settings' => ['aMount' => require dirname(__DIR__) . '/config/aMount.php']],
            'app' => ['fallback_locale' => 'en'],
        ]);
    }

    /** Reset between tests so one test's settings cannot leak into the next. */
    public static function reset(): void
    {
        self::$config = self::defaults();
        self::$repository = null;
        self::$loginUserId = 0;
    }
}

AmountTestCore::reset();
define('EVO_CLASS', AmountTestCore::class);

// The translator needs a booted container; the message text is not what these
// tests are about, so the key is echoed back.
if (!function_exists('__')) {
    function __($key = null, $replace = [], $locale = null)
    {
        $text = (string) $key;

        foreach ((array) $replace as $search => $value) {
            $text = str_replace(':' . $search, (string) $value, $text);
        }

        return $text;
    }
}

// Loaded from here rather than a `Pest.php`: Pest's own root is the borrowed
// core, which would never discover one living in this package.
require __DIR__ . '/helpers.php';

register_shutdown_function(static function () use ($root) {
    foreach ([$root, $root . '-remote'] as $path) {
        amountRemoveTree($path);
    }
});
