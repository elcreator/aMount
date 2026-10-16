<?php

use Elcreator\aMount\Facades\aMount;
use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Delivery;

beforeEach(fn () => amountReset());

function amountDeliverError(callable $fn): ?AmountException
{
    try {
        $fn();
    } catch (AmountException $e) {
        return $e;
    }

    return null;
}

it('delivers a file by creating an ephemeral rule and starting a run', function () {
    amountUser(2);
    $mount = amountMount(2, [], 'My Drive');
    amountSite(['assets/files/cat.png' => 'png-bytes']);

    $result = Delivery::for(2)->deliver('assets/files/cat.png', 'my drive', 'Pictures/Cats');

    $rule = Rule::query()->where('ephemeral', true)->first();
    $run = Run::query()->where('uuid', $result['run'])->first();

    expect($result['mount'])->toBe($mount->uuid)
        ->and($result['remote'])->toBe('Pictures/Cats')
        ->and($rule->local_path)->toBe('assets/files/cat.png')
        ->and($rule->origin)->toBe('api')
        ->and($rule->mode)->toBe('copy')
        ->and($rule->visibility)->toBe('private')
        ->and($rule->interval_minutes)->toBe(0)
        ->and($rule->owner_id)->toBe(2)
        ->and($rule->remote_prefix)->toBe('Pictures/Cats')
        ->and($run->rule_id)->toBe($rule->getKey())
        ->and($run->started_by)->toBe(2)
        ->and($run->direction)->toBe('up');

    // Not listed with the folder rules, never scheduled.
    expect(\Elcreator\aMount\Services\Rules::class)->toBeString();
    expect((new \Elcreator\aMount\Services\Rules(\Elcreator\aMount\Services\Actor::for(2)))->list())->toHaveCount(0);
});

it('honours move, public and origin options, and the same facade works', function () {
    amountUser(2);
    amountMount(2);
    amountSite(['assets/files/a.txt' => 'a']);

    aMount::for(2)->deliver('assets/files/a.txt', null, null, ['move' => true, 'public' => true, 'origin' => 'aimage']);

    $rule = Rule::query()->first();

    expect($rule->mode)->toBe('move')->and($rule->visibility)->toBe('public')->and($rule->origin)->toBe('aimage');
});

it('jails a manager without amount_manage under their own folder on a site mount', function () {
    amountUser(2);
    amountMount();
    amountSite(['assets/files/a.txt' => 'a']);

    $result = Delivery::for(2)->deliver('assets/files/a.txt', null, 'reports');

    expect($result['remote'])->toBe('u2/reports');
});

it('asks which mount when the hint is ambiguous and carries the candidates', function () {
    amountUser(2);
    amountMount(2, [], 'Alpha cloud');
    amountMount(2, [], 'Beta cloud');
    amountSite(['assets/files/a.txt' => 'a']);

    foreach ([null, 'cloud'] as $hint) {
        $e = amountDeliverError(fn () => Delivery::for(2)->deliver('assets/files/a.txt', $hint));

        expect($e->error)->toBe('ambiguous_mount')->and($e->status)->toBe(409)
            ->and(array_column($e->candidates, 'name'))->toBe(['Alpha cloud', 'Beta cloud'])
            ->and(json_encode($e->candidates))->not->toContain('root');
    }

    $unknown = amountDeliverError(fn () => Delivery::for(2)->deliver('assets/files/a.txt', 'dropbox'));
    expect($unknown->status)->toBe(404)
        ->and(Rule::query()->count())->toBe(0)
        ->and(Run::query()->count())->toBe(0)
        ->and(Delivery::for(2)->resolveMount('alpha')['name'])->toBe('Alpha cloud');
});

it('refuses a file the user cannot see in the file manager', function () {
    amountUser(2, ['amount', 'file_manager'], [], null, 'assets/files');
    amountUser(3, ['amount', 'file_manager']);
    amountMount();
    amountSite(['assets/other/secret.txt' => 's', 'assets/files/ok.txt' => 'ok', 'assets/backup/dump.sql' => 'sql', 'assets/files/restricted/x.txt' => 'x']);
    amountRestrict('assets/files/restricted', 9);

    foreach (['assets/other/secret.txt', 'assets/backup/dump.sql'] as $path) {
        $e = amountDeliverError(fn () => Delivery::for(2)->deliver($path));
        expect($e->status)->toBe(403)->and($e->error)->toBe('path_denied');
    }

    expect(amountDeliverError(fn () => Delivery::for(3)->deliver('assets/backup/dump.sql'))->status)->toBe(403)
        ->and(amountDeliverError(fn () => Delivery::for(3)->deliver('assets/files/../../core'))->error)->toBe('error_path_invalid')
        ->and(amountDeliverError(fn () => Delivery::for(2)->deliver('assets/files/missing.txt'))->status)->toBe(403)
        ->and(Rule::query()->count())->toBe(0);

    expect(Delivery::for(2)->deliver('assets/files/ok.txt'))->toHaveKey('run');
});

it('refuses move for a user who may not modify the file', function () {
    amountUser(3, ['amount', 'bk_manager']);
    amountMount();
    amountSite(['assets/backup/dump.sql' => 'sql']);

    expect(amountDeliverError(fn () => Delivery::for(3)->deliver('assets/backup/dump.sql', null, null, ['move' => true]))->status)->toBe(403)
        ->and(Delivery::for(3)->deliver('assets/backup/dump.sql'))->toHaveKey('run');
});

it('refuses users without the amount permission', function () {
    amountUser(2, ['file_manager']);
    amountMount();
    amountSite(['assets/files/a.txt' => 'a']);

    expect(amountDeliverError(fn () => Delivery::for(2)->deliver('assets/files/a.txt'))->status)->toBe(403)
        ->and(Delivery::for(2)->mounts())->toBe([])
        ->and(amountDeliverError(fn () => Delivery::for(999)->deliver('assets/files/a.txt'))->status)->toBe(403);
});

it('lists only mounts the user may deliver to, without any settings', function () {
    amountUser(2);
    amountUser(3);
    amountMount(2, [], 'Mine');
    amountMount(3, [], 'Theirs');
    amountMount(null, [], 'Shared');

    $mounts = Delivery::for(2)->mounts();

    expect(array_column($mounts, 'name'))->toBe(['Mine', 'Shared'])
        ->and(array_keys($mounts[0]))->toBe(['uuid', 'name', 'driver', 'driver_label', 'scope', 'status'])
        ->and(Delivery::for(2)->browse($mounts[0]['uuid'])['folders'])->toBe([])
        ->and(amountDeliverError(fn () => Delivery::for(2)->browse(amountMount(3)->uuid))->status)->toBe(404);
});

it('reports the status of a run to its owner only', function () {
    amountUser(2);
    amountUser(3);
    amountUser(4, ['amount', 'amount_manage', 'file_manager']);
    amountMount(2);
    amountSite(['assets/files/a.txt' => 'a']);
    $run = Delivery::for(2)->deliver('assets/files/a.txt')['run'];

    $status = Delivery::for(2)->status($run);

    expect($status)->toHaveKeys(['run', 'status', 'percent', 'finished', 'files_total', 'bytes_done'])
        ->and($status['run'])->toBe($run)
        ->and(Delivery::for(4)->status($run)['run'])->toBe($run)
        ->and(amountDeliverError(fn () => Delivery::for(3)->status($run))->status)->toBe(404)
        ->and(amountDeliverError(fn () => Delivery::for(2)->status('nope'))->status)->toBe(404);
});

it('fetches a file back through an ordinary download run and never overwrites', function () {
    amountUser(2);
    $mount = amountMount(2, [], 'My Drive');
    amountEnsureDir(amountRemoteRoot($mount) . '/backups');
    file_put_contents(amountRemoteRoot($mount) . '/backups/report.txt', 'remote report');

    $result = Delivery::for(2)->fetch('my drive', 'backups/report.txt', 'assets/files');

    expect($result['files'])->toBe(1)
        ->and(FileEntry::query()->count())->toBe(1)
        ->and(FileEntry::query()->first()->remote_path)->toBe('backups/report.txt');

    $run = Run::query()->where('uuid', $result['run'])->first();
    expect($run->direction)->toBe('down');

    amountDrive($run);

    expect(file_get_contents(amountSitePath('assets/files/report.txt')))->toBe('remote report');

    // The file now exists: a second fetch is refused up front, not silently overwritten.
    file_put_contents(amountSitePath('assets/files/report.txt'), 'local edit');
    $again = amountDeliverError(fn () => Delivery::for(2)->fetch('my drive', 'backups/report.txt', 'assets/files'));

    expect($again->error)->toBe('run_refused')
        ->and(file_get_contents(amountSitePath('assets/files/report.txt')))->toBe('local edit');
});

it('refuses to fetch into a folder the user cannot see, or something that is not there', function () {
    amountUser(2, ['amount', 'file_manager'], [], null, 'assets/files');
    $mount = amountMount(2);
    file_put_contents(amountRemoteRoot($mount) . '/a.txt', 'a');
    amountSite(['assets/other/x.txt' => 'x']);

    expect(amountDeliverError(fn () => Delivery::for(2)->fetch('', 'a.txt', 'assets/other'))->status)->toBeIn([403, 422])
        ->and(amountDeliverError(fn () => Delivery::for(2)->fetch('', 'nope.txt', 'assets/files'))->status)->toBe(404)
        ->and(Rule::query()->count())->toBe(0);
});
