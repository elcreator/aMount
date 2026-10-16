<?php

use Elcreator\aMount\Drivers\Driver;
use Elcreator\aMount\Drivers\Drivers;
use League\Flysystem\Filesystem;

beforeEach(fn () => amountReset());

/** The English strings, read straight from the file the translator would load. */
function amountLang(): array
{
    return require dirname(__DIR__, 2) . '/lang/en/global.php';
}

/** Whether a lang key exists; on a miss the failure message names the driver and key. */
function amountHas(array $lang, string $driver, string $key): bool
{
    $name = str_replace('aMount::global.', '', $key);

    if (!array_key_exists($name, $lang)) {
        throw new RuntimeException("driver {$driver}: missing lang key {$name}");
    }

    return true;
}

it('describes every driver completely, with labels that exist in lang/en', function () {
    $lang = amountLang();

    expect(Drivers::all())->not->toBeEmpty();

    foreach (Drivers::all() as $id => $driver) {
        $d = $driver->describe();

        expect($d)->toHaveKeys(['id', 'label', 'group', 'description', 'available', 'packages', 'install', 'fields', 'defaults', 'supports_visibility', 'needs_public_url'])
            ->and($d['id'])->toBe($id)
            ->and($d['label'])->not->toBe('')
            ->and($d['packages'])->not->toBeEmpty()
            ->and(amountHas($lang, $id, $d['description']))->toBeTrue();

        foreach ($d['fields'] as $field) {
            expect($field)->toHaveKeys(['name', 'type', 'label'], "driver {$id}")
                ->and(['text', 'password', 'textarea', 'select', 'bool', 'number'])->toContain($field['type'])
                ->and(amountHas($lang, $id, $field['label']))->toBeTrue();

            if (isset($field['help'])) {
                expect(amountHas($lang, $id, $field['help']))->toBeTrue();
            }

            if ($field['type'] === 'select') {
                expect($field['options'])->not->toBeEmpty();
            }

            // Anything that holds a credential must be flagged so it is masked.
            if ($field['type'] === 'password' || $field['type'] === 'textarea') {
                expect($field['secret'] ?? false)->toBeTrue("driver {$id}: {$field['name']} must be secret");
            }
        }
    }
});

it('only uses lang keys that exist', function () {
    $lang = amountLang();
    $used = [];

    foreach (['Drivers', 'Services', 'Listeners', 'Console', 'Mcp', 'Queue', 'Workers'] as $dir) {
        $path = dirname(__DIR__, 2) . '/src/' . $dir;

        if (!is_dir($path)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (preg_match_all("/aMount::global\.([a-z0-9_]+)['\"]/", (string) file_get_contents($file->getPathname()), $m)) {
                array_push($used, ...$m[1]);
            }
        }
    }

    $missing = array_values(array_filter(array_unique($used), fn ($key) => !str_ends_with($key, '_') && !array_key_exists($key, $lang)));

    expect($missing)->toBe([]);
});

it('reports the install command of a driver whose package is missing', function () {
    $missing = array_filter(Drivers::all(), fn (Driver $d) => !$d->available());

    foreach ($missing as $driver) {
        expect($driver->installCommand())->toContain('package:installrequire')->toContain($driver->packages()[0]);
        expect(fn () => $driver->filesystem([]))->toThrow(RuntimeException::class, $driver->installCommand());
    }

    expect(Drivers::get('local')->available())->toBeTrue();
});

it('builds the local driver and round-trips a file', function () {
    $root = AMOUNT_TEST_REMOTE . '/driver-local';
    amountEnsureDir($root);
    $fs = Drivers::get('local')->filesystem(['root' => $root]);

    $fs->write('a/b.txt', 'hello');

    expect($fs)->toBeInstanceOf(Filesystem::class)
        ->and($fs->read('a/b.txt'))->toBe('hello')
        ->and($fs->fileSize('a/b.txt'))->toBe(5)
        ->and(file_get_contents($root . '/a/b.txt'))->toBe('hello');

    $fs->delete('a/b.txt');
    expect($fs->fileExists('a/b.txt'))->toBeFalse();
});

it('refuses to build a driver without its required settings', function () {
    expect(fn () => Drivers::get('local')->filesystem([]))->toThrow(RuntimeException::class)
        ->and(Drivers::get('s3')->validate([]))->toHaveKey('bucket')
        ->and(Drivers::get('s3')->validate(['bucket' => 'b', 'key' => 'k']))->toHaveKey('secret')
        ->and(Drivers::get('s3')->validate(['bucket' => 'b']))->toBe([]);
});

it('constructs the network drivers without touching the network', function (string $id, array $config) {
    $driver = Drivers::get($id);

    if (!$driver->available() || ($id === 'ftp' && !defined('FTP_BINARY'))) {
        $this->markTestSkipped($id . ' package not installed');
    }

    expect($driver->filesystem($config))->toBeInstanceOf(Filesystem::class);
})->with([
    's3' => ['s3', ['bucket' => 'b', 'region' => 'eu-central-1', 'key' => 'k', 'secret' => 's']],
    's3 compatible' => ['s3', ['bucket' => 'b', 'endpoint' => 'http://minio:9000', 'path_style' => true, 'key' => 'k', 'secret' => 's']],
    'sftp' => ['sftp', ['host' => 'example.invalid', 'username' => 'u', 'password' => 'p', 'root' => '/backups']],
    'ftp' => ['ftp', ['host' => 'example.invalid', 'username' => 'u', 'password' => 'p', 'root' => '/']],
    'webdav' => ['webdav', ['base_uri' => 'https://example.invalid/dav/', 'username' => 'u', 'password' => 'p']],
]);

it('passes visibility to the adapter only when the mount opts in', function () {
    $s3 = Drivers::get('s3');

    expect($s3->writeConfig([], 'public'))->toBe([])
        ->and($s3->writeConfig(['apply_visibility' => true], 'public'))->toHaveKey('visibility')
        ->and(Drivers::get('gdrive')->writeConfig(['apply_visibility' => true], 'public'))->toBe([]);
});
