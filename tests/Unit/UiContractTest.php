<?php

/**
 * A coarse tripwire between the manager page and the JSON backend, which were
 * written separately: every request the script makes must hit a registered
 * route, and every response field it reads must exist in the code that builds
 * the answer. It reads source text only, so it needs no booted CMS.
 */

function amountUiRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return array<string, string> file name => source of every script partial */
function amountUiPartials(): array
{
    $sources = [];

    foreach (glob(amountUiRoot() . '/views/partials/script*.blade.php') as $file) {
        $sources[basename($file)] = (string) file_get_contents($file);
    }

    return $sources;
}

/** Splits at the first comma that is not inside brackets or a string. */
function amountUiSplitArgument(string $text, int $from): array
{
    $depth = 0;
    $quote = null;
    $length = strlen($text);

    for ($i = $from; $i < $length; $i++) {
        $c = $text[$i];

        if ($quote !== null) {
            if ($c === '\\') {
                $i++;
            } elseif ($c === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($c === "'" || $c === '"') {
            $quote = $c;
        } elseif ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            if ($depth === 0) {
                return [substr($text, $from, $i - $from), $i];
            }
            $depth--;
        } elseif ($c === ',' && $depth === 0) {
            return [substr($text, $from, $i - $from), $i];
        }
    }

    return [substr($text, $from), $length];
}

/** Top-level occurrences of $needle in a JS expression (outside strings and brackets). */
function amountUiTopLevel(string $expr, string $needle): array
{
    $depth = 0;
    $quote = null;
    $found = [];

    for ($i = 0, $n = strlen($expr); $i < $n; $i++) {
        $c = $expr[$i];

        if ($quote !== null) {
            if ($c === '\\') {
                $i++;
            } elseif ($c === $quote) {
                $quote = null;
            }
        } elseif ($c === "'" || $c === '"') {
            $quote = $c;
        } elseif ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            $depth--;
        } elseif ($depth === 0 && $c === $needle) {
            $found[] = $i;
        }
    }

    return $found;
}

/**
 * The URL shapes a path expression can produce: string literals stay, anything
 * dynamic becomes {x}, a ternary yields both branches.
 *
 * @return string[]
 */
function amountUiExpand(string $expr): array
{
    $expr = trim($expr);

    // A whole-expression pair of parentheses: the "(" closes at the very end.
    if (str_starts_with($expr, '(') && str_ends_with($expr, ')') && amountUiTopLevel('x' . $expr, '+') === [] && amountUiTopLevel('x' . $expr, '?') === []) {
        $inner = substr($expr, 1, -1);
        $depth = 0;
        $closesEarly = false;

        foreach (str_split($inner) as $c) {
            $depth += $c === '(' ? 1 : ($c === ')' ? -1 : 0);
            $closesEarly = $closesEarly || $depth < 0;
        }

        if (!$closesEarly) {
            return amountUiExpand($inner);
        }
    }

    $question = amountUiTopLevel($expr, '?');

    if ($question !== []) {
        $rest = substr($expr, $question[0] + 1);
        $colon = amountUiTopLevel($rest, ':');

        return array_merge(amountUiExpand(substr($rest, 0, $colon[0])), amountUiExpand(substr($rest, $colon[0] + 1)));
    }

    $variants = [''];
    $cut = array_merge([-1], amountUiTopLevel($expr, '+'), [strlen($expr)]);

    for ($i = 0; $i < count($cut) - 1; $i++) {
        $token = trim(substr($expr, $cut[$i] + 1, $cut[$i + 1] - $cut[$i] - 1));
        $options = preg_match("/^'([^']*)'$/", $token, $m) ? [$m[1]]
            : (str_starts_with($token, '(') ? amountUiExpand($token) : ['{x}']);
        $next = [];

        foreach ($variants as $v) {
            foreach ($options as $o) {
                $next[] = $v . $o;
            }
        }

        $variants = $next;
    }

    return $variants;
}

/** @return array<int, array{0:string, 1:string, 2:string}> [verb, path, file] of every request in the partials */
function amountUiRequests(): array
{
    $found = [];

    foreach (amountUiPartials() as $file => $source) {
        preg_match_all("/\bapi\(\s*'(GET|POST)'\s*,\s*/", $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $i => [$text, $offset]) {
            [$argument] = amountUiSplitArgument($source, $offset + strlen($text));

            // A path held in a variable cannot be resolved here; the scripts spell paths inline.
            expect(trim($argument))->toMatch("/^[('\"]|^[a-z.]+\s*\?/", "$file: api() path is not a literal: $argument");

            foreach (amountUiExpand($argument) as $path) {
                $found[] = [$matches[1][$i][0], $path, $file];
            }
        }

        // Plain links: BASE + '/files/' + id + '/download'
        preg_match_all("/\bBASE\s*\+\s*([^,}]+?)(?=,|\})/", $source, $links);

        foreach ($links[1] as $expression) {
            foreach (amountUiExpand($expression) as $path) {
                $found[] = ['GET', $path, $file];
            }
        }
    }

    return $found;
}

/** @return array<int, array{0:string, 1:string}> [verb, path with {x} placeholders] in registration order */
function amountUiRoutes(): array
{
    $source = (string) file_get_contents(amountUiRoot() . '/src/Http/routes.php');
    preg_match_all("/Route::(get|post)\('([^']+)'/", $source, $m, PREG_SET_ORDER);

    return array_map(static fn ($r) => [strtoupper($r[1]), preg_replace('/\{[^}]+\}/', '{x}', $r[2])], $m);
}

it('only requests routes that the backend registers', function () {
    $routes = amountUiRoutes();
    $requests = amountUiRequests();

    expect($requests)->not->toBeEmpty();

    $missing = [];

    foreach ($requests as [$verb, $path, $file]) {
        $segments = explode('/', trim($path, '/'));
        $hit = false;

        foreach ($routes as [$routeVerb, $routePath]) {
            $parts = explode('/', trim($routePath, '/'));

            if ($routeVerb !== $verb || count($parts) !== count($segments)) {
                continue;
            }

            $same = true;

            foreach ($parts as $k => $part) {
                if ($part !== '{x}' && $part !== $segments[$k] && $segments[$k] !== '{x}') {
                    $same = false;
                }
            }

            $hit = $hit || $same;
        }

        if (!$hit) {
            $missing[] = "$verb $path ($file)";
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

it('registers fixed routes before the {uuid} ones that would swallow them', function () {
    $routes = array_map(static fn ($r) => $r[0] . ' ' . $r[1], amountUiRoutes());

    foreach ($routes as $i => $route) {
        if (!preg_match('#^POST (/\w+)/(\w+)$#', $route, $m) || $m[2] === '{x}') {
            continue;
        }

        $generic = array_search('POST ' . $m[1] . '/{x}', $routes, true);

        if ($generic !== false) {
            expect($i)->toBeLessThan($generic, "$route must come before POST {$m[1]}/{uuid}");
        }
    }

    expect($routes)->toContain('POST /mounts/test');
});

it('reads only response fields that the controllers and services produce', function () {
    $root = amountUiRoot() . '/src';
    $js = implode("\n", amountUiPartials());
    $read = static fn (string $file) => (string) file_get_contents($root . '/' . $file);

    // [what the script reads, [source files that must mention it as a key, key]]
    $contract = [
        'r.csrf_token' => [['Http/Controllers/BootstrapController.php'], 'csrf_token'],
        'r.drivers' => [['Http/Controllers/BootstrapController.php'], 'drivers'],
        'r.mounts' => [['Http/Controllers/BootstrapController.php'], 'mounts'],
        'r.rules' => [['Http/Controllers/BootstrapController.php'], 'rules'],
        'r.presets' => [['Http/Controllers/BootstrapController.php'], 'presets'],
        'r.queue' => [['Http/Controllers/BootstrapController.php'], 'queue'],
        'r.flags' => [['Http/Controllers/BootstrapController.php'], 'flags'],
        'r.groups' => [['Http/Controllers/BootstrapController.php'], 'groups'],
        'r.limits' => [['Http/Controllers/BootstrapController.php'], 'limits'],
        'description_text' => [['Http/Controllers/BootstrapController.php'], 'description_text'],
        'label_text' => [['Http/Controllers/BootstrapController.php'], 'label_text'],
        'help_text' => [['Http/Controllers/BootstrapController.php'], 'help_text'],
        'r.runs' => [['Http/Controllers/RunController.php'], 'runs'],
        'r.run ' => [['Http/Controllers/RunController.php', 'Http/Controllers/RuleController.php', 'Http/Controllers/MountController.php', 'Http/Controllers/FileController.php'], 'run'],
        'r.data' => [['Services/Files.php'], 'data'],
        'page.pages' => [['Services/Files.php'], 'pages'],
        'page.total' => [['Services/Files.php'], 'total'],
        'r.mount ' => [['Http/Controllers/MountController.php'], 'mount'],
        'r.result' => [['Http/Controllers/MountController.php', 'Http/Controllers/ServerController.php'], 'result'],
        'r.errors' => [['Services/AmountException.php'], 'errors'],
        'r.folders' => [['Http/Controllers/LocalController.php', 'Services/Mounts.php'], 'folders'],
        'r.offloadable' => [['Http/Controllers/LocalController.php'], 'offloadable'],
        'r.reason' => [['Http/Controllers/LocalController.php'], 'reason'],
        'r.publishable' => [['Http/Controllers/LocalController.php'], 'publishable'],
        'r.snippets' => [['Http/Controllers/ServerController.php'], 'snippets'],
        'r.results' => [['Http/Controllers/ServerController.php'], 'results'],
        'r.server' => [['Http/Controllers/ServerController.php'], 'server'],
        'run.percent' => [['Http/Controllers/Controller.php'], 'percent'],
        'run.active' => [['Http/Controllers/Controller.php'], 'active'],
        'run.current_file' => [['Http/Controllers/Controller.php'], 'current_file'],
        'r.usage' => [['Http/Controllers/Controller.php'], 'usage'],
        'r.target' => [['Http/Controllers/Controller.php'], 'target'],
        'existing.secrets_set' => [['Services/Mounts.php'], 'secrets_set'],
        'm.credentials_unreadable' => [['Services/Mounts.php'], 'credentials_unreadable'],
        'm.last_checked_at' => [['Services/Mounts.php'], 'last_checked_at'],
        'r.mount_name' => [['Services/Rules.php'], 'mount_name'],
        'r.interval_minutes' => [['Services/Rules.php'], 'interval_minutes'],
        'r.remote_prefix' => [['Services/Rules.php'], 'remote_prefix'],
        'row.size_human' => [['Services/Files.php'], 'size_human'],
        'row.local_removed' => [['Services/Files.php'], 'local_removed'],
        'st.include_path' => [['Services/ServerConfig.php'], 'include_path'],
        'st.drift' => [['Services/ServerConfig.php'], 'drift'],
        'q.queues' => [['Queue/Dispatcher.php'], 'queues'],
        'q.hints' => [['Queue/Dispatcher.php'], 'hints'],
    ];

    $problems = [];

    foreach ($contract as $token => [$files, $key]) {
        if (!str_contains($js, $token)) {
            // The contract table lags behind a script that stopped reading it: that is fine, but say so.
            $problems[] = "the script no longer reads `$token`: drop it from this table";

            continue;
        }

        $found = false;

        foreach ($files as $file) {
            // 'key' => value, or $array['key'] = value
            $found = $found || (bool) preg_match("/['\"]" . preg_quote($key, '/') . "['\"]\s*(?:=>|\]\s*=[^=])/", $read($file));
        }

        if (!$found) {
            $problems[] = "the script reads `$token` but no answer has a '$key' key (" . implode(', ', $files) . ')';
        }
    }

    expect($problems)->toBe([]);
});

it('does not read the field names the two halves once disagreed on', function () {
    $js = implode("\n", amountUiPartials());

    // `/bootstrap` answers csrf_token; `ok`/`message` are not part of /local/check; `payload` is flat on a run.
    expect($js)->not->toContain('r.csrf)')
        ->and($js)->not->toMatch('/r\.csrf\b(?!_)/')
        ->and($js)->not->toContain('r.payload_json');
});

it('has a human text for every reason code the scope can give', function () {
    $lang = require amountUiRoot() . '/lang/en/global.php';
    $scope = (string) file_get_contents(amountUiRoot() . '/src/Support/Scope.php');
    $codes = ['missing', 'invalid'];

    // The documented list: "inactive | outside_root | ..."
    preg_match('/inactive(?:\s*\|\s*[a-z_]+)+/', $scope, $list);
    $codes = array_merge($codes, array_map('trim', explode('|', $list[0] ?? '')));

    expect(count($codes))->toBeGreaterThan(5);

    foreach ($codes as $code) {
        expect($lang)->toHaveKey('ui_reason_' . $code);
    }

    expect($lang)->toHaveKey('ui_reason_unknown');
});

it('names a status label for every mount status the model can hold', function () {
    $lang = require amountUiRoot() . '/lang/en/global.php';
    $model = (string) file_get_contents(amountUiRoot() . '/src/Models/Mount.php');
    preg_match_all("/const STATUS_[A-Z]+ = '([a-z]+)'/", $model, $m);

    expect($m[1])->not->toBeEmpty();

    foreach ($m[1] as $status) {
        expect($lang)->toHaveKey('ui_status_' . $status);
    }
});

it('runs the page script against the documented responses in node', function () {
    $node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

    if ($node === '') {
        $this->markTestSkipped('node is not installed.');
    }

    if (!function_exists('amountRenderPage')) {
        $this->markTestSkipped('The page helpers live in ViewTest; run the whole suite.');
    }

    $html = amountRenderPage();
    $scripts = amountInlineScripts($html);
    $dir = sys_get_temp_dir() . '/amount-smoke-' . getmypid();
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/app.js', end($scripts));
    file_put_contents($dir . '/island.json', json_encode(amountIslandJson($html)));

    $output = [];
    $code = 0;
    exec('node ' . escapeshellarg(amountUiRoot() . '/tests/js/smoke.cjs') . ' ' . escapeshellarg($dir . '/app.js') . ' ' . escapeshellarg($dir . '/island.json') . ' 2>&1', $output, $code);
    @unlink($dir . '/app.js');
    @unlink($dir . '/island.json');
    @rmdir($dir);

    expect($code)->toBe(0, implode("\n", $output));
});
