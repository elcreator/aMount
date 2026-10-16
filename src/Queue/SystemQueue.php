<?php

namespace Elcreator\aMount\Queue;

use Carbon\Carbon;
use Elcreator\aMount\Console\SyncHandler;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Support\Config;
use EvolutionCMS\Models\SystemCliTask;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The CMS's own task queue (`system:task-worker`).
 *
 * A row in `system_cli_tasks`, not a table of our own, so a transfer appears on
 * the worker-health page beside a site update and inherits leases and the
 * cancellation flag. `SystemTaskService` keeps its queueing method protected,
 * so the row is written here, the way aIMage does it.
 *
 * One task carries one *slice* of a run. A slice that runs out of time queues
 * its own successor (see {@see SyncHandler}), so a night-long upload shares the
 * worker with site updates instead of owning it.
 */
final class SystemQueue implements Queue
{
    public function name(): string
    {
        return 'system';
    }

    public function available(): bool
    {
        try {
            return class_exists(SystemCliTask::class) && Schema::hasTable('system_cli_tasks');
        } catch (Throwable $e) {
            return false;
        }
    }

    public function health(): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'message' => 'The CMS task queue is not available.'];
        }

        try {
            $payload = (new \EvolutionCMS\Services\SystemTasks\WorkerHealthService())->getStatusPayload();
            $status = (string) ($payload['status'] ?? 'unknown');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'The worker status is unknown.'];
        }

        return [
            'ok' => in_array($status, ['healthy', 'degraded'], true),
            'message' => 'Task worker: ' . $status . '.',
        ];
    }

    public function dispatch(Run $run): void
    {
        $task = static::enqueue($run);

        Run::query()->whereKey($run->getKey())->update([
            'queue' => $this->name(),
            'task_id' => $task === null ? null : (int) $task->getKey(),
        ]);

        $run->forceFill(['queue' => $this->name(), 'task_id' => $task === null ? null : (int) $task->getKey()])->syncOriginal();
    }

    /**
     * Queue the next slice for a run, unless one is already waiting.
     *
     * `$exceptTaskId` is the slice doing the asking: it is still `running` when
     * it asks for its successor, and without excluding it the guard would find
     * the caller, report a successor that does not exist, and stop the run dead
     * after its first slice.
     */
    public static function enqueue(Run $run, ?int $exceptTaskId = null): ?SystemCliTask
    {
        if ($run->isFinished()) {
            return null;
        }

        $existing = static::pendingTaskFor($run, $exceptTaskId);

        if ($existing !== null) {
            return $existing;
        }

        $now = now();
        $title = (string) ($run->rule?->name ?? 'aMount');

        return SystemCliTask::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => SyncHandler::TYPE,
            'target' => (string) $run->uuid,
            'requested_version' => '',
            'status' => 'queued',
            'step' => 'queued',
            'progress' => $run->percent(),
            'message' => 'Queued',
            'payload_json' => [
                'run_id' => (int) $run->getKey(),
                'run_uuid' => (string) $run->uuid,
                'display_title' => mb_substr($title, 0, 191),
                'task_type' => SyncHandler::TYPE,
            ],
            'result_json' => [],
            'created_by' => (int) $run->started_by,
            'locked_by' => '',
            'attempt_count' => 0,
            'worker_host' => '',
            'worker_pid' => null,
            'error_code' => '',
            'catalog_snapshot_hash' => '',
            'requested_by_snapshot' => ['user_id' => (int) $run->started_by],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * An unfinished task for this run, if there is one.
     *
     * A `picked`/`running` task that has not touched its row for longer than
     * `stale_after` belongs to a dead worker and does not count; otherwise an
     * abandoned run could never be queued again.
     */
    public static function pendingTaskFor(Run $run, ?int $exceptTaskId = null): ?SystemCliTask
    {
        $alive = Carbon::now()->subSeconds(max(60, (int) Config::limit('stale_after', 900)));

        return SystemCliTask::query()
            ->where('type', SyncHandler::TYPE)
            ->where('target', (string) $run->uuid)
            ->whereIn('status', ['queued', 'picked', 'running'])
            ->when($exceptTaskId !== null, static fn ($query) => $query->where('id', '!=', $exceptTaskId))
            ->where(function ($query) use ($alive) {
                $query->where('status', 'queued')->orWhere('updated_at', '>=', $alive);
            })
            ->orderByDesc('id')
            ->first();
    }
}
