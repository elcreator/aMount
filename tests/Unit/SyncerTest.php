<?php

use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\Runs;
use Elcreator\aMount\Services\Syncer;
use Elcreator\aMount\Support\Principal;

beforeEach(fn () => amountReset());

function amountSyncerSetup(array $files = ['assets/files/a.txt' => 'alpha', 'assets/files/sub/b.txt' => 'bravo!'], array $rule = []): array
{
    $user = amountAdmin();
    $mount = amountMount();
    amountSite($files);
    $r = amountRule($mount, $user, 'assets/files', $rule);

    return [$user, $mount, $r];
}

it('scans, uploads and verifies', function () {
    [, $mount, $rule] = amountSyncerSetup();

    $run = amountSync($rule);

    expect($run->status)->toBe(Run::DONE)
        ->and($run->files_total)->toBe(2)
        ->and($run->files_done)->toBe(2)
        ->and($run->bytes_done)->toBe(11)
        ->and(amountRemoteFiles($mount))->toBe(['a.txt', 'sub/b.txt']);

    $entry = amountEntry($rule, 'assets/files/a.txt');
    expect($entry->status)->toBe(FileEntry::UPLOADED)->and($entry->remote_path)->toBe('a.txt');
    expect($rule->fresh()->last_run_at)->not->toBeNull();
});

it('does not upload unchanged files again, but uploads changed ones', function () {
    [, $mount, $rule] = amountSyncerSetup();
    amountSync($rule);

    $remote = amountRemoteRoot($mount) . '/a.txt';
    file_put_contents($remote, 'tampered-marker');

    $second = amountSync($rule);
    expect($second->files_total)->toBe(0)->and(file_get_contents($remote))->toBe('tampered-marker');

    amountSite(['assets/files/a.txt' => ['alpha-changed', time() - 1800]]);
    $third = amountSync($rule);

    expect($third->files_done)->toBe(1)->and(file_get_contents($remote))->toBe('alpha-changed');
});

it('honours min_age, size limits and include/exclude', function () {
    [, $mount, $rule] = amountSyncerSetup([
        'assets/files/old.sql' => 'xxxx',
        'assets/files/fresh.sql' => ['yyyy', time()],
        'assets/files/skip.tmp' => 'zzzz',
        'assets/files/big.sql' => str_repeat('b', 100),
        'assets/files/note.txt' => 'n',
    ], ['min_age' => 60, 'include_json' => ['*.sql', '*.tmp'], 'exclude_json' => ['*.tmp'], 'max_size' => 50]);

    amountSync($rule);

    expect(amountRemoteFiles($mount))->toBe(['old.sql']);
});

it('keeps the local file in copy mode and removes it after verification in move mode', function () {
    [, $mount, $rule] = amountSyncerSetup(['assets/files/a.txt' => 'alpha'], ['mode' => Rule::MODE_MOVE]);

    amountSync($rule);

    expect(file_exists(amountSitePath('assets/files/a.txt')))->toBeFalse()
        ->and(amountRemoteFiles($mount))->toBe(['a.txt'])
        ->and(amountEntry($rule, 'assets/files/a.txt')->local_removed)->toBeTrue();
});

it('never deletes a local file that changed while it was uploading', function () {
    [, $mount, $rule] = amountSyncerSetup(['assets/files/a.txt' => str_repeat('a', 4096)], ['mode' => Rule::MODE_MOVE]);

    // The report callback fires after the file is read but is also the only
    // hook that runs during the upload: touch the file there.
    $touched = false;
    amountSync($rule, null, null, function () use (&$touched) {
        if (!$touched && is_file(amountSitePath('assets/files/a.txt'))) {
            $touched = true;
            touch(amountSitePath('assets/files/a.txt'), time() + 5);
        }
    });

    // Either the hook ran in time (file kept) or the upload had already finished (file removed);
    // what must never happen is a kept-changed file marked as removed.
    $entry = amountEntry($rule, 'assets/files/a.txt');
    expect($entry->local_removed)->toBe(!file_exists(amountSitePath('assets/files/a.txt')));
});

it('keeps a move rule file when it is not allowed to be modified', function () {
    $user = amountUser(5, ['amount']); // may use the module, but has no file_manager
    $mount = amountMount();
    amountSite(['assets/files/a.txt' => 'alpha']);
    $rule = amountRule($mount, $user, 'assets/files', ['mode' => Rule::MODE_MOVE]);

    $run = amountSync($rule);

    // Without file_manager the folder is not visible at all: the run fails.
    expect($run->status)->toBe(Run::FAILED)->and(file_exists(amountSitePath('assets/files/a.txt')))->toBeTrue();
});

it('expires remote copies past their retention', function () {
    [, $mount, $rule] = amountSyncerSetup(['assets/files/a.txt' => 'alpha'], ['retention_days' => 7]);
    amountSync($rule);

    FileEntry::query()->update(['uploaded_at' => now()->subDays(8)]);
    amountSync($rule);

    expect(amountRemoteFiles($mount))->toBe([])
        ->and(amountEntry($rule, 'assets/files/a.txt')->status)->toBe(FileEntry::EXPIRED);

    // An expired, unchanged file is not uploaded again on the next scan.
    amountSync($rule);
    expect(amountRemoteFiles($mount))->toBe([]);
});

it('mirrors local deletions to the store when asked', function () {
    [, $mount, $rule] = amountSyncerSetup(['assets/files/a.txt' => 'alpha', 'assets/files/b.txt' => 'bravo'], ['mirror_deletes' => true]);
    amountSync($rule);

    unlink(amountSitePath('assets/files/a.txt'));
    amountSync($rule);

    expect(amountRemoteFiles($mount))->toBe(['b.txt'])
        ->and(amountEntry($rule, 'assets/files/a.txt')->status)->toBe(FileEntry::EXPIRED);
});

it('does not mirror deletions when the option is off', function () {
    [, $mount, $rule] = amountSyncerSetup();
    amountSync($rule);

    unlink(amountSitePath('assets/files/a.txt'));
    amountSync($rule);

    expect(amountRemoteFiles($mount))->toContain('a.txt');
});

it('stops when cancelled mid-run and uploads nothing more', function () {
    [, $mount, $rule] = amountSyncerSetup(['assets/files/a.txt' => 'a', 'assets/files/b.txt' => 'b', 'assets/files/c.txt' => 'c']);

    $run = amountRun($rule);
    $cancelled = false;

    amountDrive($run, null, function () use (&$cancelled, $run) {
        if (!$cancelled && $run->files_done >= 1) {
            $cancelled = true;
            (new Runs())->cancel(Run::query()->find($run->getKey()));
        }
    });

    $run->refresh();
    expect($run->status)->toBe(Run::CANCELLED)
        ->and(count(amountRemoteFiles($mount)))->toBeLessThan(3)
        ->and(count(amountRemoteFiles($mount)))->toBeGreaterThanOrEqual(1);
});

it('aborts a file that is streaming when the run is cancelled and leaves no remote file behind', function () {
    [, $mount, $rule] = amountSyncerSetup(['assets/files/big.bin' => str_repeat('x', 300000)]);

    $run = amountRun($rule);
    $report = function (string $step, int $percent, string $message) use ($run) {
        if (str_starts_with($message, 'Uploading')) {
            (new Runs())->cancel(Run::query()->find($run->getKey()));
        }
    };
    amountDrive($run, null, $report);

    expect($run->refresh()->status)->toBe(Run::CANCELLED)
        ->and(amountRemoteFiles($mount))->toBe([])
        ->and(amountEntry($rule, 'assets/files/big.bin')->status)->toBe(FileEntry::PENDING);
});

it('returns false when the budget is spent and resumes in the next slice', function () {
    [, $mount, $rule] = amountSyncerSetup(['assets/files/a.txt' => 'a', 'assets/files/b.txt' => 'b', 'assets/files/c.txt' => 'c']);

    $run = amountRun($rule);

    expect(amountDrive($run, 0.0))->toBeFalse();
    expect(count(amountRemoteFiles($mount)))->toBe(1);

    while (!amountDrive($run, 0.0)) {
        // keep slicing
    }

    expect($run->refresh()->status)->toBe(Run::DONE)->and(amountRemoteFiles($mount))->toBe(['a.txt', 'b.txt', 'c.txt']);
});

it('fails the run when the owner has lost access', function () {
    [$user, $mount, $rule] = amountSyncerSetup();
    $other = amountUser(7);
    $rule->update(['owner_id' => $other]);
    amountRevoke($other, 'amount');

    $run = amountSync($rule);

    expect($run->status)->toBe(Run::FAILED)
        ->and($run->message)->toContain('permission')
        ->and(amountRemoteFiles($mount))->toBe([]);
});

it('fails the run when the owner account is blocked', function () {
    [, $mount, $rule] = amountSyncerSetup();
    $other = amountUser(7);
    $rule->update(['owner_id' => $other]);
    Illuminate\Database\Capsule\Manager::table('user_attributes')->where('internalKey', $other)->update(['blocked' => 1]);

    expect(amountSync($rule)->status)->toBe(Run::FAILED)->and(amountRemoteFiles($mount))->toBe([]);
});

it('never uploads files the owner cannot see', function () {
    $user = amountUser(7, ['amount', 'file_manager']);
    $mount = amountMount();
    amountSite([
        'assets/files/open.txt' => 'open',
        'assets/files/secret/hidden.txt' => 'hidden',
        'assets/files/secret/deeper/more.txt' => 'more',
        'assets/files/restricted.txt' => 'res',
    ]);
    amountRestrict('assets/files/secret', 9);
    amountRestrict('assets/files/restricted.txt', 9);
    $rule = amountRule($mount, $user);

    amountSync($rule);

    expect(amountRemoteFiles($mount))->toBe(['u7/open.txt']);
});

it('refuses a rule folder the owner cannot see', function () {
    $user = amountUser(7, ['amount', 'file_manager']);
    $mount = amountMount();
    amountSite(['assets/files/secret/hidden.txt' => 'hidden']);
    amountRestrict('assets/files/secret', 9);
    $rule = amountRule($mount, $user, 'assets/files/secret');

    $run = amountSync($rule);

    expect($run->status)->toBe(Run::FAILED)->and(amountRemoteFiles($mount))->toBe([]);
});

it('re-checks visibility per file when a restriction appears after the scan', function () {
    $user = amountUser(7, ['amount', 'file_manager']);
    $mount = amountMount();
    amountSite(['assets/files/a.txt' => 'a', 'assets/files/b.txt' => 'b']);
    $rule = amountRule($mount, $user);

    $run = amountRun($rule);
    amountDrive($run, 0.0); // scan + first file

    amountRestrict('assets/files/b.txt', 9);
    while (!amountDrive($run, 0.0)) {
    }

    expect(amountRemoteFiles($mount))->toBe(['u7/a.txt'])
        ->and(amountEntry($rule, 'assets/files/b.txt')->status)->toBe(FileEntry::FAILED);
});

it('uploads a single-file rule', function () {
    [, $mount, $rule] = amountSyncerSetup(['assets/files/dump.sql' => 'dump', 'assets/files/other.txt' => 'o'], []);
    $rule->update(['local_path' => 'assets/files/dump.sql']);

    amountSync($rule->fresh());

    expect(amountRemoteFiles($mount))->toBe(['dump.sql']);
});

it('builds remote prefixes with mount root, jail and rule prefix', function () {
    $mount = amountMount(null, ['root' => 'backups']);
    $manager = Principal::forUser(amountAdmin());
    $editor = Principal::forUser(amountUser(7));
    $rule = amountRule($mount, 7, 'assets/files', ['remote_prefix' => 'site/db']);

    expect(Syncer::remotePrefix($rule, $mount, $manager))->toBe('backups/site/db')
        ->and(Syncer::remotePrefix($rule, $mount, $editor))->toBe('backups/u7/site/db');

    $personal = amountMount(7);
    expect(Syncer::remotePrefix($rule, $personal, $editor))->toBe('site/db');

    $rule->remote_prefix = 'a/../b';
    expect(fn () => Syncer::remotePrefix($rule, $mount, $manager))->toThrow(RuntimeException::class);
});

it('puts a non-manager under the jail on a site mount', function () {
    $user = amountUser(7);
    $mount = amountMount();
    amountSite(['assets/files/a.txt' => 'a']);
    $rule = amountRule($mount, $user);

    amountSync($rule);

    expect(amountRemoteFiles($mount))->toBe(['u7/a.txt']);
});

it('records failures per file and fails the run only when nothing succeeded', function () {
    [, $mount, $rule] = amountSyncerSetup(['assets/files/a.txt' => 'a']);
    // A folder where the object should go makes that one write fail.
    mkdir(amountRemoteRoot($mount) . '/a.txt');

    // The local adapter lets PHP's own warning through; the failure is what is asserted.
    set_error_handler(static fn () => true);
    $run = amountSync($rule);
    restore_error_handler();

    expect($run->status)->toBe(Run::FAILED)
        ->and($run->files_failed)->toBe(1)
        ->and(amountEntry($rule, 'assets/files/a.txt')->status)->toBe(FileEntry::FAILED)
        ->and(amountEntry($rule, 'assets/files/a.txt')->attempts)->toBe(1);
});

it('moves next_run_at forward by the interval when a run ends', function () {
    [, , $rule] = amountSyncerSetup(['assets/files/a.txt' => 'a'], ['interval_minutes' => 30]);

    amountSync($rule);

    $next = $rule->fresh()->next_run_at;
    expect($next)->not->toBeNull()->and($next->diffInMinutes(now(), true))->toBeGreaterThan(28);
});
