<?php

use Elcreator\aMount\Console\SyncHandler;
use Elcreator\aMount\Console\TickCommand;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Queue\Dispatcher;
use Elcreator\aMount\Queue\STaskQueue;
use Elcreator\aMount\Queue\SystemQueue;
use Elcreator\aMount\Services\Runs;
use EvolutionCMS\Models\SystemCliTask;

beforeEach(fn () => amountReset());

function amountQueuedRun(): Run
{
    amountUser(7);
    amountSite(['assets/files/a.txt' => 'a', 'assets/files/b.txt' => 'b']);

    return amountRun(amountRule(amountMount(7), 7));
}

function amountSetQueue(string $driver): void
{
    config(['cms.settings.aMount.queue.driver' => $driver]);
}

it('picks the system queue on auto when sTask is not installed', function () {
    amountSetQueue('auto');

    expect(Dispatcher::driver()->name())->toBe(class_exists(STaskQueue::class) && (new STaskQueue())->available() ? 'stask' : 'system');
});

it('forces the system queue', function () {
    amountSetQueue('system');

    expect(Dispatcher::driver())->toBeInstanceOf(SystemQueue::class);
});

it('refuses a forced sTask queue that is not usable', function () {
    amountSetQueue('stask');

    if ((new STaskQueue())->available()) {
        expect(Dispatcher::driver()->name())->toBe('stask');

        return;
    }

    expect(fn () => Dispatcher::driver())->toThrow(RuntimeException::class);
});

it('treats an unknown driver value as auto', function () {
    amountSetQueue('bogus');

    expect(Dispatcher::driver()->name())->toBeIn(['system', 'stask']);
});

it('reports the queue status for the page', function () {
    amountSetQueue('system');
    $status = Dispatcher::status();

    expect($status['driver'])->toBe('system')
        ->and($status['queues'])->toHaveKeys(['system', 'stask'])
        ->and($status['queues']['system']['available'])->toBeTrue()
        ->and($status['hints'][0]['command'])->toContain('system:task-worker');
});

it('creates the system task row for a run', function () {
    $run = amountQueuedRun();

    (new SystemQueue())->dispatch($run);

    $task = SystemCliTask::query()->where('target', $run->uuid)->firstOrFail();
    expect($task->type)->toBe(SyncHandler::TYPE)->and($task->type)->toBe('amount.sync')
        ->and($task->status)->toBe('queued')
        ->and($task->created_by)->toBe(7)
        ->and($task->payload_json['run_uuid'])->toBe($run->uuid)
        ->and($run->fresh()->queue)->toBe('system')->and($run->fresh()->task_id)->toBe($task->id);
});

it('queues a run only once, and a successor only from a running slice', function () {
    $run = amountQueuedRun();
    $queue = new SystemQueue();

    $queue->dispatch($run);
    $queue->dispatch($run);
    expect(SystemCliTask::query()->count())->toBe(1);

    $first = SystemCliTask::query()->first();
    $first->update(['status' => 'running']);

    // The running slice asks for its successor: it must not count itself.
    $next = SystemQueue::enqueue($run, $first->id);
    expect($next->id)->not->toBe($first->id)->and(SystemCliTask::query()->count())->toBe(2);

    // A second ask finds the waiting successor.
    expect(SystemQueue::enqueue($run, $first->id)->id)->toBe($next->id);
});

it('does not queue a finished run', function () {
    $run = amountQueuedRun();
    $run->update(['status' => Run::DONE]);

    expect(SystemQueue::enqueue($run))->toBeNull();
});

it('runs a task through the handler and concludes', function () {
    $run = amountQueuedRun();
    (new SystemQueue())->dispatch($run);
    $task = SystemCliTask::query()->first();
    $reports = [];

    $result = (new SyncHandler())->execute($task, function (string $step, int $progress, string $message) use (&$reports) {
        $reports[] = $message;
    });

    expect($run->refresh()->status)->toBe(Run::DONE)
        ->and($result['result']['status'])->toBe('done')
        ->and($result['result']['files_done'])->toBe(2)
        ->and($reports)->not->toBeEmpty();
});

it('answers a task whose run is gone without failing', function () {
    $run = amountQueuedRun();
    (new SystemQueue())->dispatch($run);
    $task = SystemCliTask::query()->first();
    $run->delete();

    expect((new SyncHandler())->execute($task)['result'])->toBe(['skipped' => true]);
});

it('honours the CMS cancellation flag on a task', function () {
    $run = amountQueuedRun();
    (new SystemQueue())->dispatch($run);
    $task = SystemCliTask::query()->first();
    $task->update(['cancellation_requested_at' => now()]);

    (new SyncHandler())->execute($task);

    expect($run->refresh()->status)->toBe(Run::CANCELLED)
        ->and(amountRemoteFiles($run->rule->mount))->toBe([]);
});

it('does nothing for a run that already finished', function () {
    $run = amountQueuedRun();
    $run->update(['status' => Run::DONE, 'message' => 'Done earlier.']);
    $task = SystemQueue::enqueue($run->forceFill(['status' => Run::QUEUED]));
    $run->update(['status' => Run::DONE]);

    expect((new SyncHandler())->execute($task)['message'])->toBe('Done earlier.');
});

it('runs the tick command', function () {
    $run = amountQueuedRun();
    $run->rule->update(['interval_minutes' => 10]);
    $run->delete();

    $command = new TickCommand();
    $output = new Symfony\Component\Console\Output\BufferedOutput();
    $command->setOutput(new Illuminate\Console\OutputStyle(new Symfony\Component\Console\Input\ArrayInput([]), $output));
    $command->handle();

    expect($output->fetch())->toContain('[amount:tick] started 1');
    expect(Run::query()->count())->toBe(1);
});

it('registers and uses the sTask worker when sTask is installed', function () {
    $queue = new STaskQueue();

    expect($queue->name())->toBe('stask')->and($queue->available())->toBeTrue();
})->skip(fn () => !(new STaskQueue())->available(), 'sTask is not installed.');

it('reports sTask as unavailable without it', function () {
    $queue = new STaskQueue();

    expect($queue->health()['ok'])->toBeFalse()
        ->and(fn () => $queue->dispatch(amountQueuedRun()))->toThrow(RuntimeException::class);
})->skip(fn () => (new STaskQueue())->available(), 'sTask is installed.');
