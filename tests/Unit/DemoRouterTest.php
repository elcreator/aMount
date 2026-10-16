<?php

/**
 * ci/router.php: the demo server's stand-in for the web servers' "rewrite
 * missing paths to /index.php?q=<path>" rule. Run against a throw-away docroot
 * whose index.php reports what it received.
 */

function demoRouterServer(): ?array
{
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

    if (!is_file($cgi)) {
        return null;
    }

    $root = sys_get_temp_dir() . '/amount-router-' . bin2hex(random_bytes(4));
    mkdir($root . '/assets', 0777, true);
    file_put_contents($root . '/index.php', '<?php echo json_encode(["q" => filter_input(INPUT_GET, "q"), "uri" => $_SERVER["REQUEST_URI"], "m" => $_SERVER["REQUEST_METHOD"]]);');
    file_put_contents($root . '/assets/real.txt', 'real');
    file_put_contents($root . '/dump.sql', 'secret');

    $port = random_int(20000, 40000);
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, dirname(__DIR__, 2) . '/ci/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $root . '/out.log', 'w'], 2 => ['file', $root . '/err.log', 'w']],
        $pipes
    );

    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port)) {
            return [$process, $port];
        }

        usleep(100000);
    }

    proc_terminate($process);

    return null;
}

it('hands a missing path to index.php as a real q parameter with REQUEST_URI untouched', function () {
    $server = demoRouterServer();

    if ($server === null) {
        $this->markTestSkipped('php-cgi or the built-in server is unavailable.');
    }

    [$process, $port] = $server;

    try {
        $body = json_decode(file_get_contents("http://127.0.0.1:$port/assets/gone/pixel.png?v=1"), true);
        $real = file_get_contents("http://127.0.0.1:$port/assets/real.txt");
        $sql = @file_get_contents("http://127.0.0.1:$port/dump.sql");
    } finally {
        proc_terminate($process);
    }

    expect($body['q'])->toBe('assets/gone/pixel.png')
        ->and($body['uri'])->toBe('/assets/gone/pixel.png?v=1')
        ->and($real)->toBe('real')
        ->and($sql)->toBeFalse();
});
