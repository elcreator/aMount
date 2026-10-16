<?php

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;

/**
 * The manager page is rendered with the real Blade compiler and a real
 * translator over the package's own lang files, against stub data. That proves
 * what a browser cannot be asked in a unit test: the templates compile, the
 * JSON island parses, the script is syntactically valid and every string the
 * script asks for exists.
 */

function amountViewRoot(): string
{
    return dirname(__DIR__, 2);
}

function amountRenderPage(array $overrides = []): string
{
    $root = amountViewRoot();
    $files = new Filesystem();
    $cache = sys_get_temp_dir() . '/amount-view-cache-' . getmypid();

    if (!is_dir($cache)) {
        mkdir($cache, 0775, true);
    }

    $previous = Container::getInstance();
    $container = new Container();
    $loader = new FileLoader($files, $root . '/lang');
    $loader->addNamespace('aMount', $root . '/lang');
    $container->instance('translator', new Translator($loader, 'en'));
    Container::setInstance($container);

    try {
        $blade = new BladeCompiler($files, $cache);
        $resolver = new EngineResolver();
        $resolver->register('blade', static fn () => new CompilerEngine($blade, $files));
        $finder = new FileViewFinder($files, [$root . '/views']);
        $finder->addNamespace('aMount', [$root . '/views']);
        $factory = new Factory($resolver, $finder, new Dispatcher($container));
        $factory->setContainer($container);
        $container->instance('view', $factory);

        return $factory->make('aMount::page', $overrides + [
            'translator' => $container->make('translator'),
            'baseUrl' => '/manager/amount',
            'csrfToken' => 'csrf-token-1',
            'iconsUrl' => '/manager/media/style/default/font-awesome/css/font-awesome.min.css',
            'boot' => [
                'ok' => true,
                'drivers' => [['id' => 's3', 'label' => 'Amazon S3', 'group' => 'object', 'fields' => [], 'defaults' => []]],
                'mounts' => [],
                'rules' => [],
                'presets' => [],
                'flags' => ['may_manage' => true, 'is_admin' => true, 'use_udperms' => false],
                'groups' => [['id' => 1, 'name' => 'Editors']],
                'limits' => [],
                'csrf' => 'csrf-token-1',
                'note' => "O'Brien </script><b>",
            ],
        ])->render();
    } finally {
        Container::setInstance($previous);
    }
}

/** @return string[] the bodies of all inline scripts */
function amountInlineScripts(string $html): array
{
    preg_match_all('#<script>(.*?)</script>#s', $html, $m);

    return $m[1];
}

function amountIslandJson(string $html): array
{
    $script = amountInlineScripts($html)[0];
    preg_match('#window\.AMOUNT = (.*?);\s*$#s', trim($script), $m);
    $expression = $m[1] ?? '';

    if (preg_match("#^JSON\\.parse\\('(.*)'\\)$#s", $expression, $j)) {
        // @js() JSON-encodes the JSON once more so it can sit in a JS string literal.
        $expression = (string) json_decode('"' . $j[1] . '"');
    }

    $data = json_decode($expression, true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE, 'The AMOUNT island is not valid JSON: ' . json_last_error_msg());

    return $data;
}

it('renders the page with the four tabs and the manager frame hooks', function () {
    $html = amountRenderPage();

    expect($html)
        ->toContain('<!DOCTYPE html>')
        ->toContain('font-awesome.min.css')
        ->toContain('data-tab="mounts"')
        ->toContain('data-tab="folders"')
        ->toContain('data-tab="files"')
        ->toContain('data-tab="server"')
        ->toContain('id="am-panel-mounts"')
        ->toContain('id="am-panel-server"')
        ->toContain('role="tablist"')
        ->toContain('aria-live="polite"')
        ->not->toContain('@include')
        ->not->toContain('@verbatim')
        ->not->toContain('{{');
});

it('hands the script a valid JSON island with the route base, token, strings and boot data', function () {
    $island = amountIslandJson(amountRenderPage());

    expect($island['baseUrl'])->toBe('/manager/amount')
        ->and($island['csrf'])->toBe('csrf-token-1')
        ->and($island['boot']['flags']['may_manage'])->toBeTrue()
        ->and($island['boot']['note'])->toBe("O'Brien </script><b>")
        ->and($island['lang'])->toHaveKey('ui_title')
        ->and($island['lang']['ui_tab_mounts'])->toBe('Mounts')
        // Driver field labels arrive as lang keys, so their texts must travel too.
        ->and($island['lang'])->toHaveKey('f_bucket')
        ->and($island['s3Presets'])->toHaveKey('r2');
});

it('keeps a closing script tag in data from ending the island early', function () {
    $html = amountRenderPage();

    expect(substr_count($html, '</script>'))->toBe(2);
});

it('produces a script that node accepts', function () {
    $node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

    if ($node === '') {
        $this->markTestSkipped('node is not installed.');
    }

    $scripts = amountInlineScripts(amountRenderPage());
    $app = end($scripts);
    $file = sys_get_temp_dir() . '/amount-script-' . getmypid() . '.js';
    file_put_contents($file, $app);

    $output = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    @unlink($file);

    expect($code)->toBe(0, implode("\n", $output));
});

it('builds no HTML from data in the script', function () {
    $scripts = amountInlineScripts(amountRenderPage());
    $app = end($scripts);

    expect($app)->not->toContain('innerHTML')
        ->and($app)->not->toContain('insertAdjacentHTML')
        ->and($app)->not->toContain('document.write')
        ->and($app)->not->toContain('alert(');
});

it('has every string the page and the script ask for in the English lang file', function () {
    $lang = require amountViewRoot() . '/lang/en/global.php';
    $keys = [];

    foreach ((new RecursiveIteratorIterator(new RecursiveDirectoryIterator(amountViewRoot() . '/views'))) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            preg_match_all("#['\"](ui_[a-z0-9_]+)['\"]#", (string) file_get_contents($file->getPathname()), $m);
            $keys = array_merge($keys, $m[1]);
            // Blade strings: __('aMount::global.key')
            preg_match_all("#aMount::global\\.([a-z0-9_]+)#", (string) file_get_contents($file->getPathname()), $b);
            $keys = array_merge($keys, $b[1]);
        }
    }

    // Keys built from a variable: the script composes these at run time.
    $dynamic = [];
    foreach (['personal', 'site', 'config'] as $v) {
        $dynamic[] = 'ui_scope_' . $v;
    }
    foreach (['personal', 'site'] as $v) {
        $dynamic[] = 'ui_scope_' . $v . '_help';
    }
    foreach (['auto', 'redirect', 'proxy'] as $v) {
        $dynamic[] = 'ui_serve_' . $v;
    }
    foreach (['object', 'cloud', 'server', 'other'] as $v) {
        $dynamic[] = 'ui_group_' . $v;
    }
    foreach (['ok', 'error', 'unknown', 'queued', 'scanning', 'uploading', 'done', 'failed', 'cancelled', 'pending', 'uploaded', 'expired'] as $v) {
        $dynamic[] = 'ui_status_' . $v;
    }
    foreach (['copy', 'move'] as $v) {
        $dynamic[] = 'ui_mode_' . $v;
        $dynamic[] = 'ui_rule_mode_help_' . $v;
    }
    foreach (['private', 'public'] as $v) {
        $dynamic[] = 'ui_visibility_' . $v;
        $dynamic[] = 'ui_rule_visibility_help_' . $v;
    }
    foreach (['original', 'other'] as $v) {
        $dynamic[] = 'ui_recall_' . $v;
        $dynamic[] = 'ui_recall_' . $v . '_help';
    }
    foreach (['nginx', 'apache', 'frankenphp', 'unknown'] as $v) {
        $dynamic[] = 'ui_server_' . $v;
    }

    $missing = [];

    foreach (array_unique(array_merge($keys, $dynamic)) as $key) {
        if (array_key_exists($key, $lang)) {
            continue;
        }

        // A bare prefix ("ui_group_") is completed at run time, listed above.
        if (str_ends_with($key, '_')) {
            continue;
        }

        // A prefix that other keys extend ("ui_rule_mode_help" + "_copy").
        $extended = array_filter(array_keys($lang), static fn ($k) => str_starts_with($k, $key . '_'));

        if ($extended === []) {
            $missing[] = $key;
        }
    }

    expect($missing)->toBe([]);
});

it('keeps the English lang file free of duplicate keys and empty strings', function () {
    $source = (string) file_get_contents(amountViewRoot() . '/lang/en/global.php');
    preg_match_all("#^\\s+'([a-z0-9_]+)' =>#m", $source, $m);
    $duplicates = array_keys(array_filter(array_count_values($m[1]), static fn ($n) => $n > 1));
    $lang = require amountViewRoot() . '/lang/en/global.php';

    expect($duplicates)->toBe([])
        ->and(array_filter($lang, static fn ($v) => $v === ''))->toBe([]);
});
