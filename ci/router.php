<?php

/**
 * Router for the demo's `php -S` server.
 *
 * PHP's built-in server answers a request for a missing file with its own 404
 * and never asks the CMS, so a file that aMount has moved to the store would
 * simply disappear from its URL. Production web servers need the same hand-off
 * (the Server tab of the module generates it for nginx and Apache; FrankenPHP's
 * Salo Caddyfile already does it). This is that rule for the demo server, and
 * it is the same one the Caddyfile states:
 *
 *   a path that is neither a file nor a directory is a friendly URL: rewrite it
 *   to /index.php?q=<path>&<query>.
 *
 * The rewrite has to be a real one. The CMS decides "document by alias" with
 * filter_input(INPUT_GET, 'q'), which reads the query string the SAPI received
 * and ignores $_GET, so setting $_GET['q'] here would make it serve the start
 * page (and SEO-strict would redirect to "/"). The built-in server cannot
 * rewrite its own request, so index.php is run by php-cgi with the rewritten
 * query string, REQUEST_URI untouched, exactly as the web servers pass it.
 *
 * Files that exist are served as they are; PHP files under assets/, backups,
 * dumps and dot files are refused like in ht.access and ng.inx, and the core is
 * never reachable.
 */

$uri = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/');
$file = $root . $uri;

if (preg_match('#^/(core|views|vendor|tmp)(/|$)#', $uri)
    || preg_match('#^/assets/(cache|backup|export|import)(/|$)#', $uri)
    || preg_match('#^/assets/.*\.php$#i', $uri)
    || preg_match('#\.(sql|sqlite|db|log|bak|old|orig|save|swp|swo|tpl|inc|ini|env|dist|example|yml|yaml|lock|tar)(\.(gz|bz2|xz|zip|tgz))?$#i', $uri)
    || preg_match('#(^|/)\.#', $uri)) {
    http_response_code(404);

    return true;
}

if ($uri === '/' || is_file($file) || is_dir($file)) {
    return false;
}

$query = 'q=' . rawurlencode(ltrim($uri, '/'));

if (($_SERVER['QUERY_STRING'] ?? '') !== '') {
    $query .= '&' . $_SERVER['QUERY_STRING'];
}

$env = [
    'GATEWAY_INTERFACE' => 'CGI/1.1',
    'REDIRECT_STATUS' => '200',
    'SERVER_PROTOCOL' => $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1',
    'SERVER_SOFTWARE' => 'aMount demo router',
    'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'localhost',
    'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? '80',
    'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'REQUEST_URI' => $_SERVER['REQUEST_URI'],
    'QUERY_STRING' => $query,
    'DOCUMENT_ROOT' => $root,
    'SCRIPT_NAME' => '/index.php',
    'SCRIPT_FILENAME' => $root . '/index.php',
    'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'REMOTE_PORT' => $_SERVER['REMOTE_PORT'] ?? '0',
    'PATH' => (string) getenv('PATH'),
];

foreach (getallheaders() as $name => $value) {
    $key = strtoupper(str_replace('-', '_', $name));

    if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
        $env[$key] = $value;
    } else {
        $env['HTTP_' . $key] = $value;
    }
}

$body = (string) file_get_contents('php://input');
$process = proc_open(
    [PHP_BINARY === '' ? 'php-cgi' : dirname(PHP_BINARY) . '/php-cgi'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']],
    $pipes,
    $root,
    $env
);

if (!is_resource($process)) {
    http_response_code(502);
    echo "php-cgi is not available\n";

    return true;
}

fwrite($pipes[0], $body);
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);
proc_close($process);

$parts = preg_split("/\r?\n\r?\n/", $output, 2);
$head = $parts[0];
$content = $parts[1] ?? '';

foreach (preg_split("/\r?\n/", $head) as $line) {
    if (!str_contains($line, ':')) {
        continue;
    }

    [$name, $value] = array_map('trim', explode(':', $line, 2));

    if (strcasecmp($name, 'Status') === 0) {
        http_response_code((int) $value);
    } else {
        header($name . ': ' . $value, false);
    }
}

echo $content;

return true;
