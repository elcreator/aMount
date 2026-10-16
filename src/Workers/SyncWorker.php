<?php

namespace Elcreator\aMount\Workers;

use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\Runs;
use Elcreator\aMount\Services\Syncer;
use Elcreator\aMount\Support\Principal;
use RuntimeException;
use Seiger\sTask\Models\sTaskModel;
use Seiger\sTask\Workers\BaseWorker;

// Keep the package loadable on installations without the optional sTask.
if (!class_exists(BaseWorker::class)) {
    return;
}

/**
 * Runs one aMount run as one sTask task.
 *
 * No slicing: sTask has no cancel and its duplicate guard would hand back the
 * running task for identical meta, so a run is never re-queued from inside
 * itself. Progress, the heartbeat and cancellation live in `amount_runs`, which
 * is also what makes the page identical whichever queue carried the run.
 */
class SyncWorker extends BaseWorker
{
    public function identifier(): string
    {
        return 'aMountSync';
    }

    public function scope(): string
    {
        return 'aMount';
    }

    public function icon(): string
    {
        return '<i class="fas fa-cloud-upload-alt"></i>';
    }

    public function title(): string
    {
        return \Elcreator\aMount\Support\Lang::get('aMount::global.queue_worker_title');
    }

    public function description(): string
    {
        return \Elcreator\aMount\Support\Lang::get('aMount::global.queue_worker_description');
    }

    /** Transfers are started from the aMount page; there is nothing to configure here. */
    public function renderWidget(): string
    {
        return '';
    }

    /**
     * Create the task for a run, as the account that started that run.
     *
     * `started_by` is set explicitly: the scheduler has no session, and the
     * worker re-checks that account when the task runs.
     */
    public function createTask(string $action, ?array $options = null): sTaskModel
    {
        $uuid = trim((string) ($options['run_uuid'] ?? ''));
        $run = $uuid === '' ? null : Run::query()->where('uuid', $uuid)->first();

        if ($run === null) {
            throw new RuntimeException('aMount transfers are started from the aMount page.');
        }

        $task = parent::createTask($action, ['run_uuid' => $uuid]);
        $task->update(['started_by' => (int) $run->started_by]);

        return $task;
    }

    /**
     * Run the whole run.
     *
     * @throws RuntimeException when the run failed, so the sTask runner records the task as failed
     */
    public function taskMake(sTaskModel $task, array $opt = []): void
    {
        $uuid = trim((string) ($opt['run_uuid'] ?? ((array) $task->meta)['run_uuid'] ?? ''));
        $run = $uuid === '' ? null : Run::query()->where('uuid', $uuid)->first();

        if ($run === null) {
            $this->markFinished($task, null, 'The run this task refers to no longer exists.');

            return;
        }

        // The task runs without a session: ask about the account that started it, as it stands now.
        $principal = Principal::forUser((int) $task->started_by);

        if (!$principal->mayUse()) {
            (new Runs())->cancel($run);

            throw new RuntimeException('The account that started this transfer may no longer use aMount.');
        }

        @set_time_limit(0);
        $task->update(['status' => sTaskModel::TASK_STATUS_RUNNING]);

        $syncer = new Syncer($run, function (string $step, int $progress, string $message) use ($task) {
            $this->pushProgress($task, ['status' => 'running', 'progress' => $progress, 'message' => $message]);
        });
        $syncer->work(null);
        $run->refresh();

        $task->update(['progress' => $run->percent()]);

        if ($run->status === Run::FAILED) {
            throw new RuntimeException((string) ($run->message ?: 'The transfer failed.'));
        }

        $this->markFinished($task, null, (string) ($run->message ?: $run->status));
    }
}
