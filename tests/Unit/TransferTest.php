<?php

use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\Runs;
use Elcreator\aMount\Services\Transfer;
use Elcreator\aMount\Support\Paths;
use Elcreator\aMount\Support\Principal;
use Elcreator\aMount\Support\Scope;
use Elcreator\aMount\Support\TransferAborted;

beforeEach(fn () => amountReset());

/** Upload a few files for a user, then return everything a test needs. */
function amountStored(array $files = ['assets/files/backup.sql' => 'DUMP-CONTENT'], int $user = 1, array $rule = []): array
{
    $user === 1 ? amountAdmin() : amountUser($user, ['amount', 'file_manager']);
    $mount = amountMount();
    amountSite($files);
    $r = amountRule($mount, $user, 'assets/files', $rule);
    amountSync($r);

    return [$user, $mount, $r];
}

function amountRecall(int $entryId, int $user, array $options = []): Run
{
    $run = (new Runs())->recall([$entryId], $user, $options);
    amountDrive($run);

    return $run->refresh();
}

it('downloads a file back to its original place', function () {
    [$user, , $rule] = amountStored();
    unlink(amountSitePath('assets/files/backup.sql'));

    $run = amountRecall(amountEntry($rule, 'assets/files/backup.sql')->id, $user);

    expect($run->status)->toBe(Run::DONE)
        ->and($run->direction)->toBe('down')
        ->and(file_get_contents(amountSitePath('assets/files/backup.sql')))->toBe('DUMP-CONTENT')
        ->and(file_exists(amountSitePath('assets/files/backup.sql.amount-part')))->toBeFalse();
});

it('downloads into another folder, creating it when missing', function () {
    [$user, , $rule] = amountStored();

    $run = amountRecall(amountEntry($rule, 'assets/files/backup.sql')->id, $user, ['to' => 'assets/files/restored/2026']);

    expect($run->status)->toBe(Run::DONE)
        ->and(file_get_contents(amountSitePath('assets/files/restored/2026/backup.sql')))->toBe('DUMP-CONTENT');
});

it('refuses to overwrite unless asked', function () {
    [$user, , $rule] = amountStored();
    $id = amountEntry($rule, 'assets/files/backup.sql')->id;

    expect(fn () => (new Runs())->recall([$id], $user, []))->toThrow(RuntimeException::class, 'already exists');

    file_put_contents(amountSitePath('assets/files/backup.sql'), 'LOCAL-EDIT');
    $run = amountRecall($id, $user, ['overwrite' => true]);

    expect($run->status)->toBe(Run::DONE)->and(file_get_contents(amountSitePath('assets/files/backup.sql')))->toBe('DUMP-CONTENT');
});

it('refuses to overwrite a file the user may not modify even when asked', function () {
    [$user, , $rule] = amountStored(['assets/files/backup.sql' => 'DUMP'], 7);
    $id = amountEntry($rule, 'assets/files/backup.sql')->id;
    amountRevoke($user, 'file_manager');
    // Still allowed to use aMount through another door, but not to modify files.
    Illuminate\Database\Capsule\Manager::table('role_permissions')->insert(['role_id' => 107, 'permission' => 'bk_manager']);

    expect(fn () => (new Runs())->recall([$id], $user, ['overwrite' => true]))->toThrow(RuntimeException::class);
});

it('refuses executable names unless the user may edit code', function () {
    [$user, , $rule] = amountStored(['assets/files/shell.php' => '<?php', 'assets/files/.htaccess' => 'x', 'assets/files/a.php.jpg' => 'y'], 7);
    unlink(amountSitePath('assets/files/shell.php'));

    foreach (['shell.php', '.htaccess', 'a.php.jpg'] as $name) {
        $id = amountEntry($rule, 'assets/files/' . $name)->id;
        expect(fn () => (new Runs())->recall([$id], $user, ['to' => 'assets/files/out']))->toThrow(RuntimeException::class, 'executable');
    }

    Illuminate\Database\Capsule\Manager::table('role_permissions')->insert(['role_id' => 107, 'permission' => 'save_snippet']);
    $run = amountRecall(amountEntry($rule, 'assets/files/shell.php')->id, $user, ['to' => 'assets/files/out']);

    expect($run->status)->toBe(Run::DONE)->and(file_exists(amountSitePath('assets/files/out/shell.php')))->toBeTrue();
});

it('recognises executable and server configuration names', function () {
    foreach (['index.php', 'x.PHTML', 'a.php.bak.txt', '.htaccess', '.user.ini', 'web.config', 'run.sh', 'x.phar'] as $name) {
        expect(Transfer::isExecutableName($name))->toBeTrue($name);
    }

    foreach (['backup.sql.gz', 'photo.jpg', 'notes.txt', 'archive.zip'] as $name) {
        expect(Transfer::isExecutableName($name))->toBeFalse($name);
    }
});

it('refuses an invisible or protected destination', function () {
    [$user, , $rule] = amountStored(['assets/files/backup.sql' => 'DUMP'], 7);
    amountSite(['assets/files/secret/keep.txt' => 'k', 'assets/backup/old.sql' => 'o']);
    amountRestrict('assets/files/secret', 9);
    $id = amountEntry($rule, 'assets/files/backup.sql')->id;

    foreach (['assets/files/secret', 'assets/files/secret/new', 'assets/backup', 'core', '../outside', 'assets/files/../../x'] as $to) {
        expect(fn () => (new Runs())->recall([$id], $user, ['to' => $to]))->toThrow(RuntimeException::class);
    }

    expect(file_exists(amountSitePath('assets/files/secret/backup.sql')))->toBeFalse();
});

it('refuses entries of another manager', function () {
    [, , $rule] = amountStored(['assets/files/backup.sql' => 'DUMP'], 7);
    $other = amountUser(8, ['amount', 'file_manager']);

    expect(fn () => (new Runs())->recall([amountEntry($rule, 'assets/files/backup.sql')->id], $other, ['to' => 'assets/files/x']))
        ->toThrow(RuntimeException::class);
});

it('writes to .amount-part and renames only when complete', function () {
    [, $mount] = amountStored(['assets/files/big.bin' => str_repeat('z', 200000)]);
    $fs = $mount->filesystem();
    $target = amountSitePath('assets/files/restored.bin');
    $seen = [];

    Transfer::download($fs, 'big.bin', $target, null, false, function (int $chunk) use ($target, &$seen) {
        $seen[] = [file_exists($target . '.amount-part'), file_exists($target)];

        return true;
    });

    expect($seen)->not->toBeEmpty()
        ->and(array_unique($seen, SORT_REGULAR))->toBe([[true, false]])
        ->and(file_exists($target . '.amount-part'))->toBeFalse()
        ->and(filesize($target))->toBe(200000);
});

it('leaves no partial file behind when a download is aborted', function () {
    [, $mount] = amountStored(['assets/files/big.bin' => str_repeat('z', 200000)]);
    $target = amountSitePath('assets/files/restored.bin');

    expect(fn () => Transfer::download($mount->filesystem(), 'big.bin', $target, null, false, fn () => false))
        ->toThrow(TransferAborted::class);

    expect(file_exists($target))->toBeFalse()->and(file_exists($target . '.amount-part'))->toBeFalse();
});

it('does not replace an existing file by itself', function () {
    [, $mount] = amountStored();
    file_put_contents(amountSitePath('assets/files/here.sql'), 'mine');

    expect(fn () => Transfer::download($mount->filesystem(), 'backup.sql', amountSitePath('assets/files/here.sql')))
        ->toThrow(RuntimeException::class);
    expect(file_get_contents(amountSitePath('assets/files/here.sql')))->toBe('mine');
});

it('restores the recorded mtime so the next scan does not upload it again', function () {
    [$user, $mount, $rule] = amountStored(['assets/files/backup.sql' => 'DUMP'], 1, ['mode' => Rule::MODE_MOVE]);
    $entry = amountEntry($rule, 'assets/files/backup.sql');

    expect($entry->local_removed)->toBeTrue()->and(file_exists(amountSitePath('assets/files/backup.sql')))->toBeFalse();

    amountRecall($entry->id, $user);
    clearstatcache();

    expect((int) filemtime(amountSitePath('assets/files/backup.sql')))->toBe((int) $entry->mtime)
        ->and($entry->fresh()->local_removed)->toBeFalse();

    $again = amountSync($rule);
    expect($again->files_total)->toBe(0)->and($entry->fresh()->status)->toBe(FileEntry::UPLOADED);
});

it('can delete the remote copy after a verified download', function () {
    [$user, $mount, $rule] = amountStored();
    unlink(amountSitePath('assets/files/backup.sql'));
    $entry = amountEntry($rule, 'assets/files/backup.sql');

    $run = amountRecall($entry->id, $user, ['delete_remote' => true]);

    expect($run->status)->toBe(Run::DONE)
        ->and(amountRemoteFiles($mount))->toBe([])
        ->and($entry->fresh()->status)->toBe(FileEntry::EXPIRED);

    // An expired, unchanged file is not uploaded again.
    expect(amountSync($rule)->files_total)->toBe(0);
});

it('keeps the remote copy when it is not asked to delete it', function () {
    [$user, $mount, $rule] = amountStored();
    unlink(amountSitePath('assets/files/backup.sql'));

    amountRecall(amountEntry($rule, 'assets/files/backup.sql')->id, $user);

    expect(amountRemoteFiles($mount))->toBe(['backup.sql']);
});

it('re-validates the destination in the worker, as the user stands now', function () {
    [$user, , $rule] = amountStored(['assets/files/backup.sql' => 'DUMP'], 7);
    unlink(amountSitePath('assets/files/backup.sql'));
    $run = (new Runs())->recall([amountEntry($rule, 'assets/files/backup.sql')->id], $user, []);

    amountRevoke($user, 'file_manager');
    amountDrive($run);
    $run->refresh();

    expect($run->status)->toBe(Run::FAILED)->and(file_exists(amountSitePath('assets/files/backup.sql')))->toBeFalse();
});

it('computes destinations without touching the disk', function () {
    [, , $rule] = amountStored();
    $scope = Scope::for(Principal::forUser(1));
    $entry = amountEntry($rule, 'assets/files/backup.sql');

    expect(Transfer::destination($scope, $entry, 'assets/files/new', false))->toBe(Paths::absolute('assets/files/new/backup.sql'))
        ->and(is_dir(amountSitePath('assets/files/new')))->toBeFalse();
});
