<?php

use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\Runs;
use EvolutionCMS\Models\SystemCliTask;
use Illuminate\Database\Capsule\Manager as Capsule;

beforeEach(fn () => amountReset());

function amountRunsRule(array $attributes = [], int $owner = 7): Rule
{
    amountUser($owner);
    amountSite(['assets/files/a.txt' => 'a']);

    return amountRule(amountMount($owner), $owner, 'assets/files', $attributes);
}

it('starts a run and hands it to the queue', function () {
    $rule = amountRunsRule();

    $run = (new Runs())->start($rule, 7);

    expect($run->status)->toBe(Run::QUEUED)
        ->and($run->queue)->toBe('system')
        ->and($run->task_id)->not->toBeNull()
        ->and($run->direction)->toBe('up')
        ->and($run->started_by)->toBe(7);

    $task = SystemCliTask::query()->find($run->task_id);
    expect($task->type)->toBe('amount.sync')->and($task->target)->toBe($run->uuid);
});

it('refuses a second run while one is active', function () {
    $rule = amountRunsRule();
    (new Runs())->start($rule, 7);

    expect(fn () => (new Runs())->start($rule, 7))->toThrow(RuntimeException::class, 'already running');
});

it('refuses disabled rules and unauthorized users', function () {
    $rule = amountRunsRule(['enabled' => false]);
    expect(fn () => (new Runs())->start($rule, 7))->toThrow(RuntimeException::class, 'disabled');

    $rule->update(['enabled' => true]);
    $stranger = amountUser(8, ['amount', 'file_manager']);
    expect(fn () => (new Runs())->start($rule, $stranger))->toThrow(RuntimeException::class);

    $noModule = amountUser(9, ['file_manager']);
    $rule->update(['owner_id' => $noModule]);
    expect(fn () => (new Runs())->start($rule, $noModule))->toThrow(RuntimeException::class);

    $admin = amountAdmin();
    expect((new Runs())->start($rule->fresh()->forceFill(['owner_id' => 7]), $admin)->started_by)->toBe($admin);
});

it('may start again after the run ended', function () {
    $rule = amountRunsRule();
    $run = (new Runs())->start($rule, 7);
    amountDrive($run);

    expect((new Runs())->start($rule, 7)->id)->not->toBe($run->id);
});

it('cancels a queued run and keeps it cancelled', function () {
    $rule = amountRunsRule(['interval_minutes' => 15]);
    $run = (new Runs())->start($rule, 7);

    (new Runs())->cancel($run);
    amountDrive($run);

    expect($run->refresh()->status)->toBe(Run::CANCELLED)
        ->and($rule->fresh()->next_run_at)->not->toBeNull();
});

it('starts due rules from tick and no others', function () {
    amountUser(7);
    amountSite(['assets/files/a.txt' => 'a']);
    $mount = amountMount(7);
    $due = amountRule($mount, 7, 'assets/files', ['name' => 'due', 'interval_minutes' => 10, 'next_run_at' => now()->subMinute()]);
    $never = amountRule($mount, 7, 'assets/files', ['name' => 'never run', 'interval_minutes' => 10]);
    $later = amountRule($mount, 7, 'assets/files', ['name' => 'later', 'interval_minutes' => 10, 'next_run_at' => now()->addHour()]);
    $manual = amountRule($mount, 7, 'assets/files', ['name' => 'manual', 'interval_minutes' => 0, 'next_run_at' => now()->subHour()]);
    $off = amountRule($mount, 7, 'assets/files', ['name' => 'off', 'interval_minutes' => 10, 'enabled' => false, 'next_run_at' => now()->subHour()]);
    $once = amountRule($mount, 7, 'assets/files', ['name' => 'ephemeral', 'interval_minutes' => 10, 'ephemeral' => true, 'next_run_at' => now()->subHour()]);

    $result = (new Runs())->tick();

    expect($result['started'])->toBe(2);

    $ran = Run::query()->pluck('rule_id')->all();
    expect($ran)->toContain($due->id, $never->id)->not->toContain($later->id, $manual->id, $off->id, $once->id);
    expect(Run::query()->where('trigger', 'schedule')->count())->toBe(2);

    // A second tick finds them running and starts nothing.
    expect((new Runs())->tick()['started'])->toBe(0);
});

it('postpones a due rule whose owner lost access instead of retrying every minute', function () {
    $rule = amountRunsRule(['interval_minutes' => 30, 'next_run_at' => now()->subMinute()]);
    amountRevoke(7, 'amount');

    $result = (new Runs())->tick();

    expect($result['started'])->toBe(0)->and($result['skipped'])->toBe(1)
        ->and(Run::query()->count())->toBe(0)
        ->and($rule->fresh()->next_run_at->isFuture())->toBeTrue();
});

it('queues an abandoned run again and renews its heartbeat', function () {
    $rule = amountRunsRule();
    $run = (new Runs())->start($rule, 7);
    $first = $run->task_id;

    // Fresh: left alone.
    expect((new Runs())->reapStale())->toBe(0);

    // The worker died: the task is running, but nothing was reported for an hour.
    SystemCliTask::query()->whereKey($first)->update(['status' => 'running', 'updated_at' => now()->subHour()]);
    Run::query()->whereKey($run->id)->update(['status' => Run::UPLOADING, 'heartbeat_at' => now()->subHour()]);

    expect((new Runs())->reapStale())->toBe(1);

    $run->refresh();
    expect($run->task_id)->not->toBe($first)->and($run->heartbeat_at->isToday())->toBeTrue()
        ->and((new Runs())->reapStale())->toBe(0);
});

it('does not queue a second task while the first still waits', function () {
    $rule = amountRunsRule();
    $run = (new Runs())->start($rule, 7);
    Run::query()->whereKey($run->id)->update(['heartbeat_at' => now()->subHour()]);

    expect((new Runs())->reapStale())->toBe(1)
        ->and(SystemCliTask::query()->count())->toBe(1);
});

it('purges old history and keeps recent and active runs', function () {
    $rule = amountRunsRule();
    $old = amountRun($rule, 7, ['status' => Run::DONE, 'finished_at' => now()->subDays(90)]);
    $recent = amountRun($rule, 7, ['status' => Run::DONE, 'finished_at' => now()->subDays(2)]);
    $active = amountRun($rule, 7, ['status' => Run::UPLOADING]);

    $result = (new Runs())->tick();

    expect($result['purged'])->toBe(1)
        ->and(Run::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Run::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(Run::query()->whereKey($active->id)->exists())->toBeTrue();
});

it('computes next_run_at = now + interval when a scheduled run ends', function () {
    $rule = amountRunsRule(['interval_minutes' => 45]);
    amountDrive((new Runs())->start($rule, 7));

    $rule->refresh();
    expect($rule->last_run_at)->not->toBeNull()
        ->and((int) round($rule->last_run_at->diffInMinutes($rule->next_run_at, true)))->toBe(45);
});

it('leaves next_run_at empty for manual-only rules', function () {
    $rule = amountRunsRule();
    amountDrive((new Runs())->start($rule, 7));

    expect($rule->fresh()->next_run_at)->toBeNull();
});
