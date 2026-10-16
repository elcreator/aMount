<?php

namespace Elcreator\aMount\Http\Controllers;

use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Files;
use Elcreator\aMount\Services\Rules;
use Elcreator\aMount\Services\Runs;
use Elcreator\aMount\Services\Serve;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The index of what was offloaded, and the ways back: a background download
 * into the site, or a direct download to the browser.
 */
class FileController extends Controller
{
    private function files(): Files
    {
        return new Files($this->actor());
    }

    /** `rule` (uuid), `status`, `visibility`, `q` (path contains) and `page` filters. */
    public function index(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $ruleUuid = $this->text($request, 'rule');
            $rule = $ruleUuid === '' ? null : (new Rules($this->actor()))->find($ruleUuid);

            return $this->files()->entries($rule, [
                'status' => $this->text($request, 'status'),
                'visibility' => $this->text($request, 'visibility'),
                'q' => $this->text($request, 'q'),
            ], max(1, (int) $request->input('page', 1)));
        });
    }

    /** Brings files back into the site, in the background (all of one rule). */
    public function recall(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $ids = $this->ids($request, 'ids');
            $files = $this->files();

            // Every id must be one this manager can see; the run checks the rest.
            foreach ($ids as $id) {
                $files->find($id);
            }

            $run = (new Runs())->recall($ids, $this->actor()->userId(), [
                'to' => $this->text($request, 'to') ?: null,
                'overwrite' => $this->flag($request, 'overwrite'),
                'delete_remote' => $this->flag($request, 'delete_remote'),
            ]);

            return ['run' => $this->presentRun($run)];
        });
    }

    /** Deletes the object on the store and forgets the row; the local file stays. */
    public function destroy(int $id): JsonResponse
    {
        return $this->respond(function () use ($id) {
            $files = $this->files();
            $files->delete($files->find($id));

            return [];
        });
    }

    /**
     * Streams (or redirects to a temporary link for) an offloaded file.
     * Authorisation is inside {@see Serve::privateResponse()}.
     */
    public function download(Request $request, int $id): Response
    {
        try {
            $actor = $this->actor()->authorize();

            return Serve::privateResponse($this->files()->find($id), $actor, $request);
        } catch (AmountException $e) {
            return new JsonResponse(['ok' => false] + $e->toArray(), $e->status);
        }
    }
}
