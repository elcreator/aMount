<?php

use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\Runs;
use EvolutionCMS\Models\SystemCliTask;

beforeEach(fn () => amountReset());

/** Put objects straight into a local-driver mount, as if other tools had written them. */
function amountPutRemote(Mount $mount, array $objects): void
{
    foreach ($objects as $key => $size) {
        $path = amountRemoteRoot($mount) . '/' . $key;
        amountEnsureDir(dirname($path));
        file_put_contents($path, str_repeat('x', $size));
    }
}

function amountMeasure(Mount|Rule $target, int $user, ?string $path = null, bool $local = false, ?callable $report = null): Run
{
    $run = (new Runs())->measure($target, $user, $path, $local);
    amountDrive($run, null, $report);

    return $run->refresh();
}

/** Every remote object with size and mtime, to prove a measurement changed nothing. */
function amountRemoteState(Mount $mount): array
{
    $state = [];

    foreach (amountRemoteFiles($mount) as $key) {
        $state[$key] = [filesize(amountRemoteRoot($mount) . '/' . $key), filemtime(amountRemoteRoot($mount) . '/' . $key)];
    }

    return $state;
}

it('totals a mount and groups it by first path segment', function () {
    $admin = amountAdmin();
    $mount = amountMount();
    amountPutRemote($mount, ['a/x.bin' => 100, 'a/deep/y.bin' => 50, 'b/z.bin' => 10, 'top.txt' => 5]);

    $run = amountMeasure($mount, $admin);

    $usage = $mount->fresh()->usage_json;
    expect($run->status)->toBe(Run::DONE)->and($run->direction)->toBe('size')
        ->and($usage['bytes'])->toBe(165)->and($usage['files'])->toBe(4)
        ->and($usage['measured_at'])->not->toBeEmpty()
        ->and($usage['rows'])->toBe([
            ['label' => 'a', 'bytes' => 150, 'files' => 2, 'partial' => false],
            ['label' => 'b', 'bytes' => 10, 'files' => 1, 'partial' => false],
            ['label' => '/', 'bytes' => 5, 'files' => 1, 'partial' => false],
        ])
        ->and($run->files_done)->toBe(4)->and($run->bytes_done)->toBe(165);
});

it('counts objects that aMount did not create', function () {
    $admin = amountAdmin();
    $mount = amountMount();
    amountSite(['assets/files/a.txt' => 'abc']);
    $rule = amountRule($mount, $admin);
    amountSync($rule);
    amountPutRemote($mount, ['foreign/other.bin' => 1000]);

    amountMeasure($mount, $admin);

    expect($mount->fresh()->usage_json['bytes'])->toBe(1003)->and($mount->fresh()->usage_json['files'])->toBe(2);
});

it('measures a rule within its own remote prefix and stores it on the rule', function () {
    $admin = amountAdmin();
    $mount = amountMount();
    amountPutRemote($mount, ['site/db/one.sql' => 40, 'site/db/two.sql' => 60, 'site/media/p.jpg' => 7, 'elsewhere/q.bin' => 99]);
    $rule = amountRule($mount, $admin, 'assets/files', ['remote_prefix' => 'site/db']);

    $run = amountMeasure($rule, $admin);

    expect($run->status)->toBe(Run::DONE)
        ->and($rule->fresh()->usage_json['bytes'])->toBe(100)
        ->and($rule->fresh()->usage_json['files'])->toBe(2)
        ->and($mount->fresh()->usage_json)->toBeNull();
});

it('keeps a manager inside the jail when measuring a rule', function () {
    $user = amountUser(7);
    $mount = amountMount();
    amountPutRemote($mount, ['u7/a.bin' => 10, 'u8/b.bin' => 500]);
    $rule = amountRule($mount, $user);

    amountMeasure($rule, $user);

    expect($rule->fresh()->usage_json['bytes'])->toBe(10);
});

it('measures a sub-folder without overwriting the stored total', function () {
    $admin = amountAdmin();
    $mount = amountMount();
    amountPutRemote($mount, ['a/x.bin' => 100, 'b/z.bin' => 10]);

    $run = amountMeasure($mount, $admin, 'a');

    expect($run->payload_json['usage']['bytes'])->toBe(100)->and($mount->fresh()->usage_json)->toBeNull();
});

it('persists partial progress while counting', function () {
    $admin = amountAdmin();
    $mount = amountMount();
    amountPutRemote($mount, ['a/1.bin' => 10, 'a/2.bin' => 10, 'b/3.bin' => 10, 'c/4.bin' => 10]);
    $seen = [];

    $run = (new Runs())->measure($mount, $admin);
    amountDrive($run, null, function () use ($run, &$seen) {
        $stored = Run::query()->find($run->id);

        if (!empty($stored->payload_json['usage']['partial'])) {
            $seen[] = [$stored->payload_json['usage']['files'], $stored->files_done, $stored->status];
        }
    });

    expect($seen)->not->toBeEmpty()
        ->and(max(array_column($seen, 0)))->toBeLessThan(4)
        ->and($seen[0][2])->toBe(Run::SCANNING)
        ->and($run->refresh()->payload_json['usage'])->not->toHaveKey('partial');
});

it('can be cancelled while counting and writes no total', function () {
    $admin = amountAdmin();
    $mount = amountMount();
    amountPutRemote($mount, ['a/1.bin' => 10, 'a/2.bin' => 10, 'a/3.bin' => 10]);

    $run = (new Runs())->measure($mount, $admin);
    amountDrive($run, null, fn () => (new Runs())->cancel(Run::query()->find($run->id)));

    expect($run->refresh()->status)->toBe(Run::CANCELLED)->and($mount->fresh()->usage_json)->toBeNull();
});

it('heals a measurement that stopped reporting and does not queue it again', function () {
    $admin = amountAdmin();
    $mount = amountMount();
    $run = (new Runs())->measure($mount, $admin);
    $tasks = SystemCliTask::query()->count();

    Run::query()->whereKey($run->id)->update(['status' => Run::SCANNING, 'heartbeat_at' => now()->subHours(2)]);

    $result = (new Runs())->tick();

    expect($result['healed'])->toBe(1)->and($result['reaped'])->toBe(0)
        ->and($run->refresh()->status)->toBe(Run::FAILED)
        ->and(SystemCliTask::query()->count())->toBe($tasks);
});

it('leaves a measurement that is still reporting alone', function () {
    $admin = amountAdmin();
    $run = (new Runs())->measure(amountMount(), $admin);

    expect((new Runs())->tick()['healed'])->toBe(0)->and($run->refresh()->status)->toBe(Run::QUEUED);
});

it('never writes or deletes anything', function () {
    $admin = amountAdmin();
    $mount = amountMount();
    amountSite(['assets/files/a.txt' => 'abc', 'assets/files/sub/b.txt' => 'defg']);
    $rule = amountRule($mount, $admin, 'assets/files', ['mode' => Rule::MODE_MOVE, 'mirror_deletes' => true, 'retention_days' => 1]);
    amountSync($rule);
    amountPutRemote($mount, ['foreign/x.bin' => 20]);
    $remote = amountRemoteState($mount);
    $entries = Illuminate\Database\Capsule\Manager::table('amount_files')->get()->all();
    $site = [file_exists(amountSitePath('assets/files/a.txt')), file_exists(amountSitePath('assets/files/sub/b.txt'))];

    amountMeasure($mount, $admin);
    amountMeasure($rule, $admin);
    amountMeasure($rule, $admin, null, true);

    expect(amountRemoteState($mount))->toBe($remote)
        ->and(Illuminate\Database\Capsule\Manager::table('amount_files')->get()->all())->toEqual($entries)
        ->and([file_exists(amountSitePath('assets/files/a.txt')), file_exists(amountSitePath('assets/files/sub/b.txt'))])->toBe($site);
});

it('does not block the rule from running while it is measured', function () {
    $admin = amountAdmin();
    $mount = amountMount();
    amountSite(['assets/files/a.txt' => 'abc']);
    $rule = amountRule($mount, $admin);
    (new Runs())->measure($rule, $admin);

    expect((new Runs())->start($rule, $admin)->direction)->toBe('up');
});

it('counts a local folder through the scope, excluding what the manager cannot see', function () {
    $user = amountUser(7, ['amount', 'file_manager']);
    $mount = amountMount();
    amountSite([
        'assets/files/a.txt' => 'aaaa',
        'assets/files/docs/b.txt' => 'bb',
        'assets/files/secret/hidden.txt' => 'hhhhhhhhhh',
        'assets/files/restricted.txt' => 'rrr',
    ]);
    amountRestrict('assets/files/secret', 9);
    amountRestrict('assets/files/restricted.txt', 9);
    $rule = amountRule($mount, $user);

    $run = amountMeasure($rule, $user, null, true);

    $usage = $run->payload_json['usage'];
    expect($run->status)->toBe(Run::DONE)
        ->and($usage['files'])->toBe(2)->and($usage['bytes'])->toBe(6)
        ->and(array_column($usage['rows'], 'label'))->toEqualCanonicalizing(['/', 'docs'])
        ->and($rule->fresh()->usage_json)->toBeNull();
});

it('counts the whole local folder for an administrator', function () {
    $admin = amountAdmin();
    amountSite(['assets/files/a.txt' => 'aaaa', 'assets/files/secret/hidden.txt' => 'hhhhhhhhhh']);
    amountRestrict('assets/files/secret', 9);
    $rule = amountRule(amountMount(), $admin);

    expect(amountMeasure($rule, $admin, null, true)->payload_json['usage']['bytes'])->toBe(14);
});

it('fails a local measurement of a folder the manager cannot see', function () {
    $user = amountUser(7, ['amount', 'file_manager']);
    amountSite(['assets/files/secret/hidden.txt' => 'h']);
    amountRestrict('assets/files/secret', 9);
    $rule = amountRule(amountMount(), $user, 'assets/files/secret');

    $run = amountMeasure($rule, $user, null, true);

    expect($run->status)->toBe(Run::FAILED);
});

it('refuses measurements the user has no access to', function () {
    $user = amountUser(7);
    $other = amountUser(8);
    $site = amountMount();
    $theirs = amountMount(8);
    $rule = amountRule($site, 8);

    expect(fn () => (new Runs())->measure($site, $user))->toThrow(RuntimeException::class, 'administrator')
        ->and(fn () => (new Runs())->measure($theirs, $user))->toThrow(RuntimeException::class)
        ->and(fn () => (new Runs())->measure($rule, $user))->toThrow(RuntimeException::class)
        ->and(fn () => (new Runs())->measure($rule, $other, '../x'))->toThrow(RuntimeException::class)
        ->and(fn () => (new Runs())->measure($site, amountAdmin(), null, true))->toThrow(RuntimeException::class)
        ->and((new Runs())->measure(amountMount(7), $user)->direction)->toBe('size');
});

it('fails a measurement when the user lost access before the worker started', function () {
    $user = amountUser(7);
    $mount = amountMount(7);
    amountPutRemote($mount, ['a.bin' => 5]);
    $run = (new Runs())->measure($mount, $user);

    amountRevoke($user, 'amount');
    amountDrive($run);

    expect($run->refresh()->status)->toBe(Run::FAILED)->and($mount->fresh()->usage_json)->toBeNull();
});

it('refuses to measure the same thing twice at once', function () {
    $admin = amountAdmin();
    $mount = amountMount();
    (new Runs())->measure($mount, $admin);

    expect(fn () => (new Runs())->measure($mount, $admin))->toThrow(RuntimeException::class, 'already');
});
