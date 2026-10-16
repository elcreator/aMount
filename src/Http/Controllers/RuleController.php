<?php

namespace Elcreator\aMount\Http\Controllers;

use Elcreator\aMount\Services\Rules;
use Elcreator\aMount\Services\Runs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Folder rules: which site folder goes to which mount, and how.
 *
 * Validation, the permission boundary and the public-URL bookkeeping are all in
 * {@see Rules}; a refused rule comes back as 422 with `errors` (field => message).
 */
class RuleController extends Controller
{
    private function rules(): Rules
    {
        return new Rules($this->actor());
    }

    public function index(): JsonResponse
    {
        return $this->respond(function () {
            $rules = $this->rules();

            return ['rules' => $rules->list()->map(fn ($rule) => $rules->present($rule))->all(), 'presets' => $rules->presets()];
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $rules = $this->rules();

            return ['rule' => $rules->present($rules->create($this->payload($request)))];
        });
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        return $this->respond(function () use ($request, $uuid) {
            $rules = $this->rules();
            $rule = $rules->update($rules->find($uuid), $this->payload($request));

            return ['rule' => $rules->present($rule)];
        });
    }

    public function destroy(string $uuid): JsonResponse
    {
        return $this->respond(function () use ($uuid) {
            $rules = $this->rules();
            $rules->delete($rules->find($uuid));

            return [];
        });
    }

    /** Runs the rule now, in the background. */
    public function run(string $uuid): JsonResponse
    {
        return $this->respond(function () use ($uuid) {
            $run = (new Runs())->start($this->rules()->find($uuid), $this->actor()->userId(), 'manual');

            return ['run' => $this->presentRun($run)];
        });
    }

    /** Counts what the rule holds: on the store (default) or in the local folder (`local=1`). */
    public function measure(Request $request, string $uuid): JsonResponse
    {
        return $this->respond(function () use ($request, $uuid) {
            $rule = $this->rules()->find($uuid);
            $run = (new Runs())->measure($rule, $this->actor()->userId(), $this->text($request, 'path'), $this->flag($request, 'local'));

            return ['run' => $this->presentRun($run)];
        });
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $in = $request->except(['_token']);

        // Arrays are only meaningful for the pattern lists; anything else must be a scalar.
        foreach ($in as $key => $value) {
            if (is_array($value) && !in_array($key, ['include', 'exclude'], true)) {
                unset($in[$key]);
            }
        }

        return $in;
    }
}
