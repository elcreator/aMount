<?php

namespace Elcreator\aMount\Console;

use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Queue\SystemQueue;
use Elcreator\aMount\Services\Runs;
use Elcreator\aMount\Services\Syncer;
use Elcreator\aMount\Support\Config;
use EvolutionCMS\Interfaces\SystemTaskHandlerInterface;
use EvolutionCMS\Models\SystemCliTask;

/**
 * One slice of one run, executed by the CMS's own task worker.
 *
 * Works for `limits.slice_seconds`, then — if the run is not finished — queues
 * its successor and returns, so a night-long upload advances a little every
 * minute while the worker stays free for site updates in between. State lives
 * in `amount_runs` and `amount_files`, so a killed worker resumes at the next
 * file, not the first.
 */
class SyncHandler implements SystemTaskHandlerInterface
{
    /** The registered task type; also the value in `system_cli_tasks.type`. */
    public const TYPE = 'amount.sync';

    /**
     * @param callable|null $report step/progress/message reporter from the worker
     * @return array{message: string, result: array}
     */
    public function execute(SystemCliTask $task, ?callable $report = null)
    {
        $run = $this->resolveRun($task);

        if ($run === null) {
            return ['message' => 'The run this task refers to no longer exists.', 'result' => ['skipped' => true]];
        }

        if ($run->isFinished()) {
            return $this->conclude($run, (string) ($run->message ?: 'Already finished.'));
        }

        if ($this->cancelRequested($task)) {
            (new Runs())->cancel($run);

            return $this->conclude($run->refresh(), 'Cancelled.');
        }

        // The CMS's own cancel button sets a flag on the task; honour it at each
        // progress tick as well as between slices.
        $watch = function (string $step, int $progress, string $message, string $level = 'info', array $context = []) use ($task, $report, $run) {
            if ($this->cancelRequested($task)) {
                (new Runs())->cancel($run);
            }

            if ($report !== null) {
                $report($step, $progress, $message, $level, $context);
            }
        };

        $finished = (new Syncer($run, $watch))->work((float) max(5, (int) Config::limit('slice_seconds', 240)));
        $run->refresh();

        if (!$finished) {
            // Queued before returning so a crash right here still leaves the run
            // moving. This task is excluded from the "one waiting slice" guard:
            // it is still running and would otherwise count as its own successor.
            SystemQueue::enqueue($run, (int) $task->getKey());

            return [
                'message' => 'Transferred ' . $run->files_done . ' of ' . $run->files_total . ' file(s); continuing.',
                'result' => ['run_uuid' => (string) $run->uuid, 'status' => (string) $run->status, 'progress' => $run->percent(), 'continued' => true],
            ];
        }

        return $this->conclude($run, (string) ($run->message ?: $run->status));
    }

    private function conclude(Run $run, string $message): array
    {
        return [
            'message' => mb_substr($message, 0, 255),
            'result' => [
                'run_uuid' => (string) $run->uuid,
                'status' => (string) $run->status,
                'files_done' => (int) $run->files_done,
                'files_failed' => (int) $run->files_failed,
                'files_total' => (int) $run->files_total,
            ],
        ];
    }

    private function resolveRun(SystemCliTask $task): ?Run
    {
        $payload = is_array($task->payload_json) ? $task->payload_json : [];
        $id = (int) ($payload['run_id'] ?? 0);

        if ($id > 0 && ($run = Run::query()->find($id)) !== null) {
            return $run;
        }

        // `target` carries the uuid, which survives a database moved between
        // environments where an auto-increment id may not.
        $uuid = trim((string) $task->target);

        return $uuid === '' ? null : Run::query()->where('uuid', $uuid)->first();
    }

    private function cancelRequested(SystemCliTask $task): bool
    {
        return SystemCliTask::query()->whereKey($task->getKey())->whereNotNull('cancellation_requested_at')->exists();
    }
}
