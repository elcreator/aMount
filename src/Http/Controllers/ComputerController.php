<?php

namespace Elcreator\aMount\Http\Controllers;

use Elcreator\aMount\Computer\Client;
use Elcreator\aMount\Computer\ComputerException;
use Elcreator\aMount\Computer\Connect;
use Elcreator\aMount\Computer\ConnectedKey;
use Elcreator\aMount\Computer\SiteAddress;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Computers;
use Elcreator\aMount\Services\Mounts;
use Elcreator\aMount\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The account's computers on the aMount page (LendSpare's shared folders, lendspare.com docs/features/29-computer-files.md):
 * what they share, this site's code the person compares, a mount in one click, and confirming
 * a computer's new key after its program was reinstalled - and "Connect LendSpare", which leaves for
 * LendSpare's consent page and comes back with a key for this manager ({@see Connect}).
 */
final class ComputerController extends Controller
{
    private const SESSION_KEY = 'amount_lendspare_connect';

    public function index(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            // This page was opened through one of the site's own addresses: remember it for the
            // worker, which has no request to read it from (SiteAddress decides whether to trust it).
            SiteAddress::remember($request->getSchemeAndHttpHost());

            // `shares=0`: only the list (the form's picker), without asking every online computer.
            return (new Computers($this->actor()))->overview((string) $request->query('shares', '1') !== '0');
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

    /** GET: off to LendSpare's consent page; the verifier stays in this session. */
    public function connect(Request $request): RedirectResponse
    {
        if (!$this->actor()->mayUse()) {
            return $this->backToPage($request, 'failed', Lang::get('aMount::global.error_forbidden'));
        }
        $pkce = Connect::pkce();
        $_SESSION[self::SESSION_KEY] = ['verifier' => $pkce['verifier'], 'state' => $pkce['state'], 'at' => time()];
        $site = Connect::siteOrigin($request->getSchemeAndHttpHost());

        return new RedirectResponse(Connect::authorizeUrl($site, $this->callbackUrl($request), $pkce['state'], $pkce['challenge']));
    }

    /** GET, back from LendSpare with `?code&state` (or `?error`): trade the code for this manager's key. */
    public function callback(Request $request): RedirectResponse
    {
        $pending = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        $state = (string) $request->query('state', '');
        if (!is_array($pending) || $state === '' || !hash_equals((string) ($pending['state'] ?? ''), $state)
            || time() - (int) ($pending['at'] ?? 0) > Connect::PENDING_TTL) {
            return $this->backToPage($request, 'failed', Lang::get('aMount::global.error_lendspare_connect_expired'));
        }
        if ((string) $request->query('error', '') !== '') {
            return $this->backToPage($request, 'failed', Lang::get('aMount::global.error_lendspare_connect_denied'));
        }
        // The session outlives the click: the permission is checked again now.
        if (!$this->actor()->mayUse()) {
            return $this->backToPage($request, 'failed', Lang::get('aMount::global.error_forbidden'));
        }
        try {
            $key = Connect::exchange((string) $request->query('code', ''), (string) $pending['verifier'], $this->callbackUrl($request));
        } catch (ComputerException $e) {
            return $this->backToPage($request, 'failed', Lang::get('aMount::global.error_lendspare_connect', ['detail' => $e->getMessage()]));
        } catch (\Throwable) {
            return $this->backToPage($request, 'failed', Lang::get('aMount::global.error_lendspare_connect', ['detail' => 'network']));
        }
        ConnectedKey::put($this->actor()->userId(), $key);

        return $this->backToPage($request, 'connected', Lang::get('aMount::global.ui_computers_connected'));
    }

    /** POST: forget this manager's key (LendSpare keeps it until it is removed on the profile there). */
    public function disconnect(): JsonResponse
    {
        return $this->respond(function (): array {
            ConnectedKey::forget($this->actor()->userId());

            return [];
        });
    }

    /** The relay on this site's front end, which forwards to the callback route without a Referer. */
    private function callbackUrl(Request $request): string
    {
        return Connect::relayUri(Connect::siteOrigin($request->getSchemeAndHttpHost()), $this->moduleUrl($request) . '/computers/connect/callback');
    }

    /** The module's base route, from this request's own path (the prefix is a hash, never spelled out). */
    private function moduleUrl(Request $request): string
    {
        $path = $request->getBaseUrl() . $request->getPathInfo();

        return (string) preg_replace('#/computers/connect(?:/callback)?/?$#', '', $path);
    }

    private function backToPage(Request $request, string $flag, string $message): RedirectResponse
    {
        $page = $request->getSchemeAndHttpHost() . $this->moduleUrl($request) . '/?' . http_build_query(['lendspare' => $flag, 'message' => $message]);
        $manager = defined('EVO_MANAGER_URL') ? (string) EVO_MANAGER_URL : '';

        return new RedirectResponse(Connect::framedUrl($page, $manager));
    }
}
