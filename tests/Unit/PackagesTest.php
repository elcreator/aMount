<?php

use Elcreator\aMount\Drivers\Driver;
use Elcreator\aMount\Drivers\Drivers;

beforeEach(fn () => amountReset());

function amountComposer(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return string[] the PLUGIN_EXTRAS of ci/plugin.env */
function amountPluginExtras(): array
{
    $env = (string) file_get_contents(dirname(__DIR__, 2) . '/ci/plugin.env');

    return preg_match('/^PLUGIN_EXTRAS="\$\{PLUGIN_EXTRAS-([^}]*)\}"/m', $env, $m) ? preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) : [];
}

it('requires only what every installation uses', function () {
    expect(array_keys(amountComposer()['require']))
        ->toBe(['php', 'evolution-cms/evolution', 'league/flysystem', 'league/flysystem-local']);
});

it('offers every storage driver package as a suggestion, apart from the local one', function () {
    $composer = amountComposer();
    // What the required CMS brings itself (guzzle for the computer driver).
    $core = json_decode((string) file_get_contents((getenv('EVO_CORE_PATH_TEST') ?: dirname(__DIR__, 3) . '/evolution/core') . '/composer.json'), true);

    foreach (Drivers::all() as $driver) {
        foreach ($driver->packages() as $package) {
            $declared = array_key_exists($package, $composer['require']) || array_key_exists($package, $composer['suggest'])
                || array_key_exists($package, (array) ($core['require'] ?? []));

            expect($declared)->toBeTrue($driver->id() . ' needs ' . $package . ', which neither composer.json nor the CMS core declares');
        }
    }

    expect($composer['suggest'])->toHaveKeys(['league/flysystem-aws-s3-v3', 'league/flysystem-sftp-v3', 'league/flysystem-webdav']);
});

it('keeps the minimum versions of the optional drivers it depends on', function () {
    // SFTP below 3.36 takes neither several pinned keys nor the OpenSSH SHA256 form.
    expect(amountComposer()['conflict'])->toBe([
        'league/flysystem-aws-s3-v3' => '<3.10',
        'league/flysystem-sftp-v3' => '<3.36',
        'league/flysystem-webdav' => '<3.6',
    ]);
});

it('builds the published CMS with the S3, SFTP and WebDAV drivers', function () {
    $suggest = amountComposer()['suggest'];
    $extras = amountPluginExtras();

    expect($extras)->toBe(['league/flysystem-aws-s3-v3', 'league/flysystem-sftp-v3', 'league/flysystem-webdav']);

    foreach ($extras as $package) {
        expect($suggest)->toHaveKey($package);
    }
});

it('tells how to install a missing driver by its id', function () {
    foreach (Drivers::all() as $driver) {
        if ($driver->id() === 'ai-computer' && !$driver->available()) {
            continue;
        }

        expect($driver->installCommand())->toBe('cd core && php artisan amount:drivers --install=' . $driver->id());
    }
});
