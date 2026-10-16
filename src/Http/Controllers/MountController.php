<?php

namespace Elcreator\aMount\Http\Controllers;

use Elcreator\aMount\Services\Mounts;
use Elcreator\aMount\Services\Runs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Remote storage connections. Secrets are accepted here and never returned:
 * {@see Mounts::present()} masks them.
 */
class MountController extends Controller
{
    private function mounts(): Mounts
    {
        return new Mounts($this->actor());
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $mounts = $this->mounts();

            return ['mount' => $mounts->present($mounts->create($this->payload($request)))];
        });
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        return $this->respond(function () use ($request, $uuid) {
            $mounts = $this->mounts();
            $mount = $mounts->update($mounts->find($uuid), $this->payload($request));

            return ['mount' => $mounts->present($mount)];
        });
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        return $this->respond(function () use ($request, $uuid) {
            $mounts = $this->mounts();
            $mounts->delete($mounts->findForDelete($uuid), $this->flag($request, 'force'));

            return [];
        });
    }

    /** Writes, reads back and deletes a probe object on the store. */
    public function test(string $uuid): JsonResponse
    {
        return $this->respond(function () use ($uuid) {
            $mounts = $this->mounts();
            $mount = $mounts->find($uuid);
            $result = $mounts->test($mount);

            return ['result' => $result, 'mount' => $mounts->present($mount->fresh())];
        });
    }

    /**
     * Probes the settings in the form without saving anything: a new mount has
     * no row yet, and an edited one keeps its stored secrets (`uuid` in the body).
     */
    public function testDraft(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $uuid = $this->text($request, 'uuid');

            return ['result' => $this->mounts()->testDraft($this->payload($request), $uuid === '' ? null : $uuid)];
        });
    }

    /** Remote sub-folders, for the destination picker. */
    public function browse(Request $request, string $uuid): JsonResponse
    {
        return $this->respond(function () use ($request, $uuid) {
            $mounts = $this->mounts();

            return $mounts->browse($mounts->find($uuid), $this->text($request, 'path'));
        });
    }

    /** Starts the asynchronous count of what the store holds below `path`. */
    public function measure(Request $request, string $uuid): JsonResponse
    {
        return $this->respond(function () use ($request, $uuid) {
            $mount = $this->mounts()->find($uuid);
            $run = (new Runs())->measure($mount, $this->actor()->userId(), $this->text($request, 'path'));

            return ['run' => $this->presentRun($run)];
        });
    }

    /**
     * Only the fields the service understands; anything else in the body is dropped.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $in = [];

        foreach (['name', 'driver', 'scope'] as $key) {
            if ($request->has($key)) {
                $in[$key] = $this->text($request, $key);
            }
        }

        foreach (['config', 'options', 'groups'] as $key) {
            if ($request->has($key)) {
                $in[$key] = $this->listOf($request, $key);
            }
        }

        return $in;
    }
}
