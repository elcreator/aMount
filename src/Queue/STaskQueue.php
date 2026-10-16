<?php

namespace Elcreator\aMount\Queue;

use Carbon\Carbon;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Support\Config;
use Elcreator\aMount\Workers\SyncWorker;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Seiger\sTask\Models\sTaskModel;
use Seiger\sTask\Models\sWorker;
use Seiger\sTask\Services\WorkerDiscovery;
use Seiger\sTask\Workers\BaseWorker;
use Throwable;

/**
 * sTask, when it is installed.
 *
 * Soft dependency: nothing here is touched unless sTask's classes exist, and
 * the worker class itself is file-guarded the same way. A run is one sTask task
 * — no slicing — and sTask has no cancel of its own, so cancellation and
 * progress live in `amount_runs` and a run is never queued again from inside
 * itself.
 */
final class STaskQueue implements Queue
{
    public const IDENTIFIER = 'aMountSync';

    public function name(): string
    {
        return 'stask';
    }

    public function available(): bool
    {
        try {
            return class_exists(BaseWorker::class)
                && class_exists(SyncWorker::class)
                && class_exists(sTaskModel::class)
                && Schema::hasTable('s_tasks')
                && Schema::hasTable('s_workers');
        } catch (Throwable $e) {
            return false;
        }
    }

    public function health(): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'message' => 'sTask is not installed.'];
        }

        try {
            $worker = sWorker::query()->where('identifier', self::IDENTIFIER)->first();
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'The sTask worker list is unavailable.'];
        }

        return $worker !== null && $worker->active
            ? ['ok' => true, 'message' => 'sTask worker registered.']
            : ['ok' => true, 'message' => 'sTask is installed; the aMount worker is registered on first use.'];
    }

    public function dispatch(Run $run): void
    {
        if (!$this->available()) {
            throw new RuntimeException('sTask is not installed.');
        }

        static::ensureRegistered();

        $worker = app(SyncWorker::class);
        $stale = Carbon::now()->subSeconds(max(60, (int) Config::limit('stale_after', 900)));

        // sTask returns the running task for identical meta. For a run whose
        // heartbeat stopped that task is a corpse; fail it so a fresh one can
        // be created.
        $duplicate = sTaskModel::findActiveDuplicate(self::IDENTIFIER, 'make', ['run_uuid' => (string) $run->uuid]);

        if ($duplicate !== null && (int) $duplicate->status !== sTaskModel::TASK_STATUS_QUEUED
            && ($run->heartbeat_at === null || $run->heartbeat_at->lessThan($stale))) {
            $duplicate->markAsFailed('Abandoned: the worker stopped reporting.');
        }

        $task = $worker->createTask('make', ['run_uuid' => (string) $run->uuid]);

        Run::query()->whereKey($run->getKey())->update(['queue' => $this->name(), 'task_id' => (int) $task->getKey()]);
        $run->forceFill(['queue' => $this->name(), 'task_id' => (int) $task->getKey()])->syncOriginal();
    }

    /**
     * Make sure sTask knows the worker and has it switched on. Idempotent.
     */
    public static function ensureRegistered(): bool
    {
        if (!class_exists(BaseWorker::class) || !class_exists(SyncWorker::class)) {
            return false;
        }

        try {
            $worker = sWorker::query()->where('identifier', self::IDENTIFIER)->first();

            if ($worker === null) {
                $worker = app(WorkerDiscovery::class)->registerWorker(SyncWorker::class);
            }

            if ($worker !== null && !$worker->active) {
                $worker->active = true;
                $worker->save();
            }

            return $worker !== null;
        } catch (Throwable $e) {
            return false;
        }
    }
}
