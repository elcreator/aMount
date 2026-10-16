<?php

namespace Elcreator\aMount\Listeners;

use Elcreator\aMount\Services\Serve;
use Elcreator\aMount\Services\ServerConfig;
use Elcreator\aMount\Support\Config;
use Elcreator\aMount\Support\Paths;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `OnPageNotFound`: a request for a file that is no longer on disk.
 *
 * Only *public* offloaded files are answered here ({@see Serve::find()} refuses
 * everything else), anonymously, so a private backup can never be reached
 * through a guessable URL. Any doubt, any error: return and let the CMS render
 * its own 404.
 */
final class NotFound
{
    public const PROBE = '#(?:^|/)amount-probe-([a-f0-9]{16,64})\.txt$#';

    public function handle(array $params = []): void
    {
        try {
            $response = $this->response(
                (string) ($_SERVER['REQUEST_URI'] ?? ''),
                Request::createFromGlobals(),
                $this->basePath()
            );
        } catch (Throwable) {
            return;
        }

        if ($response !== null) {
            $response->send();
            exit;
        }
    }

    /** The answer for a request, or null when this is none of aMount's business. Testable: nothing is sent. */
    public function response(string $requestUri, Request $request, string $basePath = '/'): ?Response
    {
        if (!Config::isEnabled() || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $path = self::sitePath($requestUri, $basePath);

        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match(self::PROBE, $path, $m)) {
            $token = ServerConfig::probeToken(false);

            if ($token !== null && hash_equals($token, $m[1])) {
                return new Response("ok\n", 200, ['X-aMount' => 'ok', 'Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
            }

            return null;
        }

        $entry = Serve::find($path);

        return $entry === null ? null : Serve::publicResponse($entry, $request);
    }

    /** Request URI to a clean site path: base path removed, query dropped, percent-decoded. */
    public static function sitePath(string $requestUri, string $basePath = '/'): ?string
    {
        $path = rawurldecode((string) parse_url($requestUri, PHP_URL_PATH));
        $base = '/' . trim($basePath, '/');

        if ($base !== '/') {
            if (!str_starts_with($path . '/', $base . '/')) {
                return null;
            }

            $path = substr($path, strlen($base));
        }

        return Paths::clean($path);
    }

    private function basePath(): string
    {
        if (defined('MODX_BASE_URL')) {
            return (string) MODX_BASE_URL;
        }

        return (string) (evo()->getConfig('base_url') ?: '/');
    }
}
