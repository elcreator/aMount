<?php

namespace Elcreator\aMount\Http\Controllers;

use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Rules;
use Elcreator\aMount\Services\Runs;
use Elcreator\aMount\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Activity: the list the page polls while something is running, one run, and cancel.
 */
class RunController extends Controller
{
    private const LIMIT = 50;

    /** `active=1` for what is running now; `rule` (uuid) for one rule's history. */
    public function index(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $query = $this->runsQuery();

            if ($this->flag($request, 'active')) {
                $query->whereIn('status', Run::ACTIVE);
            }

            $ruleUuid = $this->text($request, 'rule');

            if ($ruleUuid !== '') {
                $query->where('rule_id', (new Rules($this->actor()))->find($ruleUuid)->getKey());
            }

            $runs = $query->orderByDesc('id')->limit(self::LIMIT)->get();

            return ['runs' => $runs->map(fn (Run $run) => $this->presentRun($run))->all()];
        });
    }

    public function show(string $uuid): JsonResponse
    {
        return $this->respond(fn () => ['run' => $this->presentRun($this->find($uuid))]);
    }

    /** Cooperative: the worker stops before its next file and drops the partial copy. */
    public function cancel(string $uuid): JsonResponse
    {
        return $this->respond(function () use ($uuid) {
            $run = $this->find($uuid);

            if ($run->isActive()) {
                (new Runs())->cancel($run);
            }

            return ['run' => $this->presentRun($run->fresh())];
        });
    }

    /** @throws AmountException a run this manager may not see is simply not found */
    private function find(string $uuid): Run
    {
        $run = $this->runsQuery()->where('uuid', trim($uuid))->first();

        if ($run === null) {
            throw AmountException::notFound(Lang::get('aMount::global.what_run'));
        }

        return $run;
    }
}
