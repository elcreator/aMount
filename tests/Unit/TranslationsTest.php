<?php

use Elcreator\aMount\Drivers\Drivers;
use Elcreator\aMount\Support\Lang;

/**
 * The English source file, checked against the code that uses it. A key the code
 * names but the file lacks renders as the raw key on the page; nothing else in the
 * suite would notice. Other locales are translated from English afterwards, so
 * only English is the reference here.
 */

function amountEnglish(): array
{
    return require dirname(__DIR__, 2) . '/lang/en/global.php';
}

/** @return array<string, string> key => first file that uses it */
function amountReferencedKeys(): array
{
    $found = [];
    $root = dirname(__DIR__, 2);

    foreach (['src', 'views'] as $folder) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!in_array($file->getExtension(), ['php', 'js'], true)) {
                continue;
            }

            // Comments describe the convention (`aMount::global.x`); only code counts.
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~\{\{--.*?--\}\}~s', '~^\s*(//|\*).*$~m'], '', (string) file_get_contents($file->getPathname()));

            if (preg_match_all('/aMount::global\.([a-z][a-z0-9_]*)/', $code, $m)) {
                foreach ($m[1] as $key) {
                    $found[$key] ??= str_replace($root . '/', '', str_replace('\\', '/', $file->getPathname()));
                }
            }
        }
    }

    return $found;
}

test('every referenced translation key exists in English', function () {
    $english = amountEnglish();
    $missing = [];

    foreach (amountReferencedKeys() as $key => $file) {
        // `aMount::global.error_` / `.preset_` style prefixes are built at run time: see the dynamic tests.
        if (!array_key_exists($key, $english) && !str_ends_with($key, '_')) {
            $missing[] = "{$key} ({$file})";
        }
    }

    expect($missing)->toBe([]);
});

test('no English string is empty', function () {
    foreach (amountEnglish() as $key => $value) {
        expect(trim((string) $value))->not->toBe('', "{$key} is empty");
    }
});

test('placeholders are well formed', function () {
    foreach (amountEnglish() as $key => $value) {
        // A lone colon-word is a placeholder; anything with uppercase or a trailing
        // underscore is a typo that would render literally.
        preg_match_all('/(?<![\w:\/]):([A-Za-z_]\w*)/', (string) $value, $m);

        foreach ($m[1] as $name) {
            expect($name)->toMatch('/^[a-z][a-z0-9_]*[a-z0-9]$|^[a-z]$/', "bad placeholder :{$name} in {$key}");
        }
    }
});

test('driver labels, descriptions and help texts all resolve', function () {
    $english = amountEnglish();
    $missing = [];

    foreach (Drivers::all() as $driver) {
        $keys = [$driver->description()];

        foreach ($driver->fields() as $field) {
            $keys[] = (string) ($field['label'] ?? '');
            $keys[] = (string) ($field['help'] ?? '');
        }

        foreach (array_filter($keys) as $key) {
            if (str_starts_with($key, 'aMount::global.') && !array_key_exists(substr($key, 15), $english)) {
                $missing[] = $driver->id() . ': ' . $key;
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

test('permission and preset keys the migration and services build exist', function () {
    $english = amountEnglish();

    foreach (['permissions_group', 'permission_use', 'permission_manage', 'preset_backups', 'preset_images', 'preset_files', 'preset_media',
        'server_instr_nginx', 'server_instr_apache', 'server_instr_frankenphp', 'server_instr_unknown', 'http_denied', 'http_title'] as $key) {
        expect($english)->toHaveKey($key);
    }
});

test('Lang falls back to the English text and fills placeholders without a booted translator', function () {
    expect(Lang::get('aMount::global.error_not_found', ['what' => 'rule']))->toBe('The rule was not found.')
        ->and(Lang::get('error_forbidden'))->toBe('You are not allowed to do this.')
        ->and(Lang::get('aMount::global.no_such_key_at_all'))->toBe('aMount::global.no_such_key_at_all');
});
