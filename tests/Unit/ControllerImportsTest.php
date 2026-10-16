<?php

/**
 * A class a controller names without importing it is a fatal error on the page
 * that uses it, and nothing else in the suite loads controllers through a running
 * CMS. This reads the source: every `Name::` / `new Name` in a controller must be
 * imported, live in the controller's own namespace, or be PHP's own.
 */

function amountControllerFiles(): array
{
    return glob(dirname(__DIR__, 2) . '/src/Http/Controllers/*.php') ?: [];
}

test('every class a controller names is imported or local', function () {
    $dir = dirname(__DIR__, 2) . '/src/Http/Controllers';
    $problems = [];

    foreach (amountControllerFiles() as $file) {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        preg_match_all('/^use\s+(?:[\w\\\\]+\\\\)?(\w+)(?:\s+as\s+(\w+))?\s*[;{]/m', $code, $uses, PREG_SET_ORDER);
        $known = ['self', 'static', 'parent'];
        foreach ($uses as $use) {
            $known[] = $use[2] ?? $use[1];
        }
        foreach (glob($dir . '/*.php') as $sibling) {
            $known[] = basename($sibling, '.php');
        }

        preg_match_all('/(?<![\\\\\w$>:])([A-Z]\w+)\s*::|\bnew\s+([A-Z]\w+)/', $code, $hits, PREG_SET_ORDER);
        foreach ($hits as $hit) {
            $name = $hit[1] !== '' ? $hit[1] : ($hit[2] ?? '');
            if ($name !== '' && !in_array($name, $known, true) && !class_exists($name) && !interface_exists($name)) {
                $problems[] = basename($file) . ': ' . $name;
            }
        }
    }

    expect(array_values(array_unique($problems)))->toBe([]);
});

test('every controller and the provider load', function () {
    foreach (amountControllerFiles() as $file) {
        $class = 'Elcreator\\aMount\\Http\\Controllers\\' . basename($file, '.php');

        expect(class_exists($class))->toBeTrue($class);
    }

    expect(class_exists(\Elcreator\aMount\Http\Controllers\Controller::class))->toBeTrue();
});

test('every route action exists and every mutation is a POST', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/routes.php');

    preg_match_all("/Route::(\w+)\('([^']*)',\s*\[(\w+)::class,\s*'(\w+)'\]/", $source, $routes, PREG_SET_ORDER);

    expect(count($routes))->toBeGreaterThan(20);

    foreach ($routes as [, $verb, $uri, $controller, $action]) {
        $class = 'Elcreator\\aMount\\Http\\Controllers\\' . $controller;

        expect(class_exists($class))->toBeTrue($class)
            ->and(method_exists($class, $action))->toBeTrue("{$class}::{$action}")
            ->and(in_array($verb, ['get', 'post'], true))->toBeTrue("{$uri} uses {$verb}");

        // CSRF fails closed for POST; a GET that changes state would bypass it.
        if (preg_match('#/(delete|run|cancel|measure|test|write|probe|recall)$#', $uri)
            || ($verb === 'get' && in_array($action, ['store', 'update', 'destroy', 'run', 'cancel', 'write', 'recall'], true))) {
            expect($verb)->toBe('post', $uri);
        }
    }
});

test('the contract routes are all declared', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/routes.php');

    foreach ([
        "get('/'", "get('/bootstrap'", "post('/mounts'", "post('/mounts/{uuid}'", "post('/mounts/{uuid}/delete'",
        "post('/mounts/{uuid}/test'", "get('/mounts/{uuid}/browse'", "post('/mounts/{uuid}/measure'",
        "get('/local/folders'", "get('/local/check'", "get('/rules'", "post('/rules'", "post('/rules/{uuid}'",
        "post('/rules/{uuid}/delete'", "post('/rules/{uuid}/run'", "post('/rules/{uuid}/measure'",
        "get('/files'", "post('/files/recall'", "post('/files/{id}/delete'", "get('/files/{id}/download'",
        "get('/runs'", "get('/runs/{uuid}'", "post('/runs/{uuid}/cancel'",
        "get('/server'", "post('/server/write'", "post('/server/probe'",
    ] as $route) {
        expect($source)->toContain('Route::' . $route);
    }
});
