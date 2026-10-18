<?php

namespace Elcreator\aMount\Http\Controllers;

use Elcreator\aMount\Computer\Client;
use Elcreator\aMount\Computer\SiteAddress;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Computers;
use Elcreator\aMount\Services\Mounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The account's computers on the aMount page (ai.artur.work docs/features/29-computer-files.md):
 * what they share, this site's code the person compares, a mount in one click, and confirming
 * a computer's new key after its program was reinstalled.
 */
final class ComputerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            // This page was opened through one of the site's own addresses: remember it for the
            // worker, which has no request to read it from (SiteAddress decides whether to trust it).
            SiteAddress::remember($request->getSchemeAndHttpHost());

            return (new Computers($this->actor()))->overview();
        });
    }

    public function mount(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $actor = $this->actor();
            $mount = (new Computers($actor))->mountFor($this->text($request, 'computer'));
            if ($mount === null) {
                throw AmountException::notFound(AmountException::text('aMount::global.what_mount'));
            }

            return ['mount' => (new Mounts($actor))->present($mount)];
        });
    }

    /** Pins belong to the whole installation, so confirming a new key is for administrators of the module. */
    public function trust(string $id): JsonResponse
    {
        return $this->respond(function () use ($id): array {
            Client::trustNewKey((int) $id);

            return [];
        }, true);
    }
}
