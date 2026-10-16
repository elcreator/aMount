<?php

namespace Elcreator\aMount\Http\Controllers;

use Elcreator\aMount\Services\ServerConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web-server integration: the rules that let offloaded public files keep their
 * URLs. Editing server configuration is an `amount_manage` matter, so every
 * endpoint here refuses everyone else.
 */
class ServerController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->respond(fn () => ['server' => ServerConfig::status(), 'snippets' => $this->snippets()], true);
    }

    /**
     * The configuration text for every supported web server, so the page can
     * show the one the administrator picks without another round trip.
     *
     * @return array<string, string>
     */
    private function snippets(): array
    {
        $dirs = ServerConfig::directories();
        $snippets = [];

        foreach (['nginx', 'apache', 'frankenphp'] as $server) {
            $snippets[$server] = ServerConfig::render($server, $dirs);
        }

        return $snippets;
    }

    /** Writes the include file (nginx, frankenphp) or the managed .htaccess block (apache). */
    public function write(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $server = $this->text($request, 'server') ?: ServerConfig::detect();
            $result = ServerConfig::write($server);

            return ['result' => $result, 'server' => ServerConfig::status()];
        }, true);
    }

    /**
     * Asks the site whether the web server hands a missing file under `dir` to the
     * CMS. Without `dir`, every directory that public rules need is probed.
     */
    public function probe(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $dir = $this->text($request, 'dir');
            $dirs = $dir === '' ? ServerConfig::directories() : [$dir];

            return ['results' => array_map([ServerConfig::class, 'probe'], $dirs)];
        }, true);
    }
}
