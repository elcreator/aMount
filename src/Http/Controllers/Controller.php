<?php

namespace Elcreator\aMount\Http\Controllers;

use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\Actor;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The HTTP face of aMount.
 *
 * Every decision - who may see which folder, whose mount this is, what a queued
 * run may do - lives in the services built on {@see Actor}; the same objects
 * answer the Delivery API and the MCP tools. A controller only turns a request
 * into one call and the answer (or refusal) into JSON, so the page and an AI
 * agent can never disagree about what a manager is allowed to do.
 *
 * Responses are `{ok:true, ...payload}` or `{ok:false, error, message}` with
 * the refusal's own HTTP status (plus `errors` and `candidates` when it has them).
 */
abstract class Controller
{
    private ?Actor $actor = null;

    protected function actor(): Actor
    {
        return $this->actor ??= Actor::current();
    }

    /**
     * Run one call and answer with its payload, or with the refusal shaped like
     * every other response, so the front end has one code path for "no".
     *
     * @param callable(): array $call
     * @param bool $administrator the call is for `amount_manage` holders only
     */
    protected function respond(callable $call, bool $administrator = false): JsonResponse
    {
        if (!$this->actor()->mayUse() || ($administrator && !$this->actor()->mayManage())) {
            return $this->denied();
        }

        try {
            return $this->ok($call());
        } catch (AmountException $e) {
            return new JsonResponse(['ok' => false] + $e->toArray(), $e->status);
        } catch (RuntimeException $e) {
            // The engine's "cannot start this now" refusals (already running,
            // disabled, owner may not): intentional, secret-free messages.
            return $this->fail('refused', $e->getMessage(), 409);
        }
    }

    protected function denied(): JsonResponse
    {
        return $this->fail('forbidden', Lang::get('aMount::global.error_forbidden'), 403);
    }

    protected function fail(string $error, string $message, int $status = 422): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $error, 'message' => $message], $status);
    }

    protected function ok(array $payload = []): JsonResponse
    {
        return new JsonResponse(['ok' => true] + $payload);
    }

    /** The session token the page sends back with every POST; empty (and so refused by `mgr`) without a session. */
    protected function csrfToken(): string
    {
        try {
            return (string) csrf_token();
        } catch (\Throwable) {
            return '';
        }
    }

    // ------------------------------------------------------------------
    // Input: typed, so a hostile array where a string belongs is a refusal
    // ------------------------------------------------------------------

    protected function text(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    protected function flag(Request $request, string $key, bool $default = false): bool
    {
        $value = $request->input($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<int|string, mixed> */
    protected function listOf(Request $request, string $key): array
    {
        $value = $request->input($key, []);

        return is_array($value) ? $value : [];
    }

    /** @return int[] */
    protected function ids(Request $request, string $key): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', array_filter($this->listOf($request, $key), 'is_scalar')),
            static fn (int $id) => $id > 0
        )));
    }

    // ------------------------------------------------------------------
    // Presentation shared by several controllers
    // ------------------------------------------------------------------

    /**
     * The runs this manager may see: all of them for an administrator, otherwise
     * the ones they started and those of rules they own.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Run>
     */
    protected function runsQuery()
    {
        $query = Run::query();

        if (!$this->actor()->mayManage()) {
            $userId = $this->actor()->userId();
            $query->where(static function ($q) use ($userId) {
                $q->where('started_by', $userId)
                    ->orWhereIn('rule_id', Rule::query()->where('owner_id', $userId)->select('id'));
            });
        }

        return $query;
    }

    /**
     * A run as the page polls it.
     *
     * @return array<string, mixed>
     */
    protected function presentRun(Run $run): array
    {
        $rule = $run->rule_id > 0 ? Rule::query()->find($run->rule_id) : null;
        $payload = is_array($run->payload_json) ? $run->payload_json : [];

        return [
            'uuid' => $run->uuid,
            'rule' => $rule?->uuid,
            'rule_name' => $rule?->name,
            'direction' => $run->direction,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'active' => $run->isActive(),
            'finished' => $run->isFinished(),
            'percent' => $run->percent(),
            'files_total' => (int) $run->files_total,
            'files_done' => (int) $run->files_done,
            'files_failed' => (int) $run->files_failed,
            'bytes_total' => (int) $run->bytes_total,
            'bytes_done' => (int) $run->bytes_done,
            'current_file' => $run->current_file,
            'message' => $run->message,
            'target' => $run->direction === 'size' ? ($payload['target'] ?? null) : null,
            'mount' => $payload['mount'] ?? null,
            'path' => $payload['path'] ?? null,
            'to' => $run->direction === 'down' ? ($payload['to'] ?? null) : null,
            // The running (partial) or last measured breakdown, for the live usage block.
            'usage' => $run->direction === 'size' ? ($payload['usage'] ?? null) : null,
            'started_by' => (int) $run->started_by,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
