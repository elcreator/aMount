<?php

use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\ServerConfig;

beforeEach(function () {
    amountReset();
    ServerConfig::$fetcher = null;
    $_SERVER['SERVER_SOFTWARE'] = '';
    AmountTestCore::config()->set('cms.settings.aMount.server.include_dir', AMOUNT_TEST_ROOT . '/include');
    amountRemoveTree(AMOUNT_TEST_ROOT . '/include');
    foreach (['/.htaccess', '/.htaccess.amount.bak'] as $file) {
        if (is_file(AMOUNT_TEST_ROOT . $file)) {
            unlink(AMOUNT_TEST_ROOT . $file);
        }
    }
});

/** The relevant part of the CMS's own ht.access. */
function amountHtaccess(string $eol = "\n"): string
{
    return implode($eol, [
        'RewriteEngine On',
        'RewriteRule ^assets/.*\.php$ - [F,L,NC]',
        '',
        '# friendly URLs',
        'RewriteRule ^(manager|assets|js|css|images|img)/.*$ - [L]',
        'RewriteRule \.(jpg|jpeg|png|gif|ico)$ - [L]',
        'RewriteCond %{REQUEST_FILENAME} !-f',
        'RewriteRule ^(.*)$ index.php?q=$1 [L,QSA]',
        '',
    ]);
}

function amountPublicRule(string $path, array $attributes = []): Rule
{
    static $mount = null;
    $mount = Elcreator\aMount\Models\Mount::query()->first() ?? amountMount();

    return amountRule($mount, 1, $path, $attributes + ['visibility' => Rule::PUBLIC, 'name' => 'r-' . $path]);
}

it('renders the nginx location for the top-level directories', function () {
    $conf = ServerConfig::render('nginx', ['uploads', 'assets']);

    expect($conf)->toContain('location ~ ^/(assets/(?!cache/|backup/|export/|import/)|uploads/) {')
        ->toContain('try_files $uri @rewrite;')
        ->toContain('expires max;')
        ->and(ServerConfig::render('nginx', []))->not->toContain('location');
});

it('refuses to put hostile directory names into a server config', function () {
    $conf = ServerConfig::render('nginx', ['good', 'bad;}', '../x', 'a b']);

    expect($conf)->toContain('good/')->not->toContain('bad')->not->toContain('..')->not->toContain('a b');
});

it('renders the apache block and a caddy note that says nothing is needed', function () {
    $block = ServerConfig::render('apache', ['assets', 'uploads']);

    expect($block)->toStartWith("# BEGIN aMount\n")->toContain("# END aMount\n")
        ->toContain('RewriteCond %{REQUEST_FILENAME} !-f')
        ->toContain('RewriteRule ^(assets/(?!cache/|backup/|export/|import/)|uploads/).+$ index.php?q=$0 [L,QSA]')
        ->and(ServerConfig::render('apache', []))->toBe('')
        ->and(ServerConfig::render('frankenphp', ['assets']))->toContain('nothing to change');

    expect(fn () => ServerConfig::render('iis', []))->toThrow(AmountException::class);
});

it('takes the directories from enabled public rules only', function () {
    amountAdmin(1);
    amountSite(['assets/files/a.txt' => 'a', 'uploads/b.txt' => 'b', 'media/c.txt' => 'c', 'docs/d.txt' => 'd', 'core/custom/e.txt' => 'e']);

    amountPublicRule('assets/files');
    amountPublicRule('uploads');
    amountPublicRule('media', ['visibility' => Rule::PRIVATE]);
    amountPublicRule('docs', ['enabled' => false]);
    amountPublicRule('core/custom');

    expect(ServerConfig::directories())->toBe(['assets', 'uploads']);
});

it('detects the web server', function () {
    $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.27';
    expect(ServerConfig::detect())->toBe('nginx');
    $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58 (Unix)';
    expect(ServerConfig::detect())->toBe('apache');
    $_SERVER['SERVER_SOFTWARE'] = 'Caddy FrankenPHP';
    expect(ServerConfig::detect())->toBe('frankenphp');
    $_SERVER['SERVER_SOFTWARE'] = '';
    expect(ServerConfig::detect())->toBe('unknown');

    AmountTestCore::config()->set('cms.settings.aMount.server.type', 'nginx');
    expect(ServerConfig::detect())->toBe('nginx');
});

it('writes the nginx include and notices when public rules outgrow it', function () {
    amountAdmin(1);
    amountSite(['assets/files/a.txt' => 'a', 'uploads/b.txt' => 'b']);
    $_SERVER['SERVER_SOFTWARE'] = 'nginx';

    expect(ServerConfig::status()['drift'])->toBeFalse();

    amountPublicRule('assets/files');
    $status = ServerConfig::status();
    expect($status['drift'])->toBeTrue()->and($status['directories'])->toBe(['assets']);

    $result = ServerConfig::write('nginx');
    $path = AMOUNT_TEST_ROOT . '/include/amount.nginx.conf';

    expect($result['changed'])->toBeTrue()->and($result['path'])->toBe($path)
        ->and(file_get_contents($path))->toContain('assets/(?!cache/')
        ->and(ServerConfig::status()['drift'])->toBeFalse()
        ->and(ServerConfig::write('nginx')['changed'])->toBeFalse();

    amountPublicRule('uploads');
    expect(ServerConfig::status()['drift'])->toBeTrue();

    // refresh() keeps an applied integration current, without being asked twice.
    ServerConfig::refresh();
    expect(file_get_contents($path))->toContain('uploads/')->and(ServerConfig::status()['drift'])->toBeFalse();
});

it('does not touch the server files on refresh() until an administrator applied them once', function () {
    amountAdmin(1);
    amountSite(['assets/files/a.txt' => 'a']);
    file_put_contents(AMOUNT_TEST_ROOT . '/.htaccess', amountHtaccess());
    $_SERVER['SERVER_SOFTWARE'] = 'Apache';
    amountPublicRule('assets/files');

    ServerConfig::refresh();

    expect(file_get_contents(AMOUNT_TEST_ROOT . '/.htaccess'))->toBe(amountHtaccess())
        ->and(is_dir(AMOUNT_TEST_ROOT . '/include'))->toBeFalse();
});

it('inserts the apache block before the assets rule, idempotently, with a backup', function () {
    amountAdmin(1);
    amountSite(['assets/files/a.txt' => 'a']);
    amountPublicRule('assets/files');
    $htaccess = AMOUNT_TEST_ROOT . '/.htaccess';
    file_put_contents($htaccess, amountHtaccess());

    $first = ServerConfig::write('apache');
    $content = file_get_contents($htaccess);

    expect($first['changed'])->toBeTrue()
        ->and(substr_count($content, '# BEGIN aMount'))->toBe(1)
        ->and(strpos($content, '# BEGIN aMount'))->toBeLessThan(strpos($content, ServerConfig::ANCHOR))
        ->and(strpos($content, '# END aMount'))->toBeLessThan(strpos($content, ServerConfig::ANCHOR))
        ->and(file_get_contents($htaccess . '.amount.bak'))->toBe(amountHtaccess());

    $second = ServerConfig::write('apache');
    expect($second['changed'])->toBeFalse()->and(file_get_contents($htaccess))->toBe($content);

    // A different set of directories replaces the block rather than stacking another.
    amountSite(['uploads/b.txt' => 'b']);
    amountPublicRule('uploads');
    ServerConfig::write('apache');
    $updated = file_get_contents($htaccess);
    expect(substr_count($updated, '# BEGIN aMount'))->toBe(1)->and($updated)->toContain('uploads/');

    // No public rules left: the block goes away and the original file is back.
    Rule::query()->delete();
    ServerConfig::write('apache');
    expect(file_get_contents($htaccess))->toBe(amountHtaccess());
});

it('keeps the line endings of the htaccess and refuses a file without the anchor', function () {
    $crlf = ServerConfig::applyBlock(amountHtaccess("\r\n"), ServerConfig::render('apache', ['assets']));

    expect($crlf)->toContain("# BEGIN aMount\r\n")->not->toMatch('/[^\r]\n/')
        ->and(ServerConfig::applyBlock("RewriteEngine On\n", ServerConfig::render('apache', ['assets'])))->toBeNull();

    amountAdmin(1);
    amountSite(['assets/files/a.txt' => 'a']);
    amountPublicRule('assets/files');

    expect(fn () => ServerConfig::write('apache'))->toThrow(AmountException::class);   // no .htaccess at all

    file_put_contents(AMOUNT_TEST_ROOT . '/.htaccess', "RewriteEngine On\n");
    expect(fn () => ServerConfig::write('apache'))->toThrow(AmountException::class);   // no anchor
});

it('writes the caddy snippet for frankenphp and rejects unknown servers', function () {
    $result = ServerConfig::write('frankenphp');

    expect(file_get_contents($result['path']))->toContain('nothing to change')
        ->and(fn () => ServerConfig::write('iis'))->toThrow(AmountException::class);

    $_SERVER['SERVER_SOFTWARE'] = 'Caddy';
    expect(ServerConfig::status()['drift'])->toBeFalse();
});

it('probes a directory through the marker the listener answers with', function () {
    $seen = null;
    ServerConfig::$fetcher = function (string $url) use (&$seen) {
        $seen = $url;

        return ['status' => 200, 'header' => 'ok'];
    };

    $ok = ServerConfig::probe('assets');
    expect($ok['ok'])->toBeTrue()->and($seen)->toBe('https://example.test/assets/amount-probe-' . ServerConfig::probeToken() . '.txt');

    ServerConfig::$fetcher = static fn () => ['status' => 404, 'header' => ''];
    expect(ServerConfig::probe('assets')['ok'])->toBeFalse();

    ServerConfig::$fetcher = static fn () => throw new RuntimeException('down');
    expect(ServerConfig::probe('assets')['ok'])->toBeFalse();

    expect(fn () => ServerConfig::probe('../x'))->toThrow(AmountException::class);
});
