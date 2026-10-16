<?php

namespace Elcreator\aMount\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The module page.
 *
 * Rendered inside the manager's main frame, so it owns its whole document. It
 * is handed only what cannot be derived in the browser: the route prefix (a
 * hash of the module name, so not guessable), the icon stylesheet and the CSRF
 * token. Everything else is fetched from `/bootstrap`.
 */
class PageController extends Controller
{
    public function index(Request $request): View
    {
        if (!$this->actor()->mayUse()) {
            return view('aMount::denied');
        }

        return view('aMount::page', [
            // Every fetch the page makes is relative to this; no script spells out a module path.
            'baseUrl' => rtrim($request->getBaseUrl() . $request->getPathInfo(), '/'),
            // The page is its own document inside the manager frame and inherits none of the
            // manager's styles: it borrows the icon font the manager ships.
            'iconsUrl' => $this->managerAsset('style/common/font-awesome/css/font-awesome.min.css'),
            'csrfToken' => $this->csrfToken(),
        ]);
    }

    /**
     * A URL for a file under `manager/media/`.
     *
     * `EVO_MANAGER_URL` is right under a request but derived from the working
     * directory when nothing serves, so a value that does not look like a URL is
     * rebuilt from `MGR_DIR`.
     */
    private function managerAsset(string $path): string
    {
        $base = defined('EVO_MANAGER_URL') ? (string) EVO_MANAGER_URL : '';

        if ($base === '' || !preg_match('#^(?:https?:)?//|^/#', $base)) {
            $base = '/' . (defined('MGR_DIR') ? trim((string) MGR_DIR, '/') : 'manager') . '/';
        }

        return rtrim($base, '/') . '/media/' . ltrim($path, '/');
    }
}
