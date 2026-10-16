<?php

namespace Elcreator\aMount\Queue;

use Elcreator\aMount\Models\Run;

/**
 * Something that can carry a run to a worker.
 *
 * A queue is only a carrier. Progress, the heartbeat and cancellation live in
 * `amount_runs`, so the page reads one table whichever queue is active and a
 * cancel works the same on both.
 */
interface Queue
{
    /** Short stable name, stored in `amount_runs.queue`: system | stask. */
    public function name(): string;

    /** Whether this queue can be used on this installation at all. */
    public function available(): bool;

    /** @return array{ok: bool, message: string} */
    public function health(): array;

    /**
     * Hand the run to a worker and record where it went in `runs.queue` and
     * `runs.task_id`. Must be safe to call twice for the same run (a retry of
     * an abandoned run is exactly that).
     */
    public function dispatch(Run $run): void;
}
