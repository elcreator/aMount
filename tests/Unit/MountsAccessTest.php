<?php

use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Services\Actor;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Mounts;

beforeEach(fn () => amountReset());

function amountMounts(int $userId): Mounts
{
    return new Mounts(Actor::for($userId));
}

function amountCatch(callable $fn): ?AmountException
{
    try {
        $fn();
    } catch (AmountException $e) {
        return $e;
    }

    return null;
}

it('hides a personal mount from everybody else, administrators included', function () {
    amountAdmin(1);
    amountUser(2);
    amountUser(3);
    $mount = amountMount(2, [], 'My Drive');

    expect(amountMounts(2)->visible())->toHaveCount(1)
        ->and(amountMounts(3)->visible())->toHaveCount(0)
        ->and(amountMounts(1)->visible())->toHaveCount(0);

    $other = amountCatch(fn () => amountMounts(3)->find($mount->uuid));
    $admin = amountCatch(fn () => amountMounts(1)->find($mount->uuid));
    $missing = amountCatch(fn () => amountMounts(3)->find('00000000-0000-0000-0000-000000000000'));

    // The refusal for a stranger's mount is indistinguishable from "no such mount".
    expect($other->status)->toBe(404)->and($admin->status)->toBe(404)
        ->and($other->getMessage())->toBe($missing->getMessage());
});

it('lets an administrator delete a personal mount but never open or edit it', function () {
    amountAdmin(1);
    amountUser(2);
    $mount = amountMount(2);

    expect(amountMounts(1)->foreign())->toHaveCount(1);
    expect(amountCatch(fn () => amountMounts(1)->update($mount, ['name' => 'x'])))->not->toBeNull();

    $found = amountMounts(1)->findForDelete($mount->uuid);
    amountMounts(1)->delete($found);

    expect(Mount::query()->count())->toBe(0);
});

it('restricts site mounts by granted user group, and lets managers see all of them', function () {
    amountAdmin(1);
    amountUser(2, ['amount', 'file_manager'], [5]);   // member of user group 105
    amountUser(3, ['amount', 'file_manager']);
    amountUser(4, ['amount', 'amount_manage', 'file_manager']);
    amountUser(5, ['file_manager']);                    // no `amount`
    $open = amountMount(null, [], 'Open');
    $closed = amountMount(null, [], 'Closed');
    $closed->grantGroups([105]);

    $names = fn (int $id) => amountMounts($id)->visible()->pluck('name')->sort()->values()->all();

    expect($names(2))->toBe(['Closed', 'Open'])
        ->and($names(3))->toBe(['Open'])
        ->and($names(4))->toBe(['Closed', 'Open'])
        ->and($names(5))->toBe([])
        ->and(amountCatch(fn () => amountMounts(3)->find($closed->uuid))->status)->toBe(404)
        ->and(amountCatch(fn () => amountMounts(5)->find($open->uuid))->status)->toBe(403);
});

it('exposes config-defined mounts as read-only site mounts', function () {
    amountAdmin(1);
    amountUser(2);
    AmountTestCore::config()->set('cms.settings.aMount.mounts', [
        'offsite' => ['name' => 'Off-site', 'driver' => 'local', 'config' => ['root' => AMOUNT_TEST_REMOTE . '/cfg'], 'groups' => []],
    ]);

    $visible = amountMounts(2)->visible();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->isFromConfig())->toBeTrue()
        ->and(amountMounts(2)->present($visible->first())['scope'])->toBe('config')
        ->and(amountMounts(1)->present($visible->first())['editable'])->toBeFalse()
        ->and(amountCatch(fn () => amountMounts(1)->update($visible->first(), ['name' => 'x'])))->not->toBeNull()
        ->and(amountCatch(fn () => amountMounts(1)->delete($visible->first())))->not->toBeNull();

    // Credentials come from config at use, so the probe works without anything stored.
    expect(amountMounts(2)->test($visible->first())['ok'])->toBeTrue();

    // Syncing again does not duplicate; removing the definition removes the row.
    amountMounts(2)->visible();
    expect(Mount::query()->count())->toBe(1);
    AmountTestCore::config()->set('cms.settings.aMount.mounts', []);
    amountMounts(2)->visible();
    expect(Mount::query()->count())->toBe(0);
});

it('never puts a secret into a presented mount and keeps it when the field is left blank', function () {
    amountUser(2);
    $mounts = amountMounts(2);

    $mount = $mounts->create([
        'name' => 'Bucket', 'driver' => 's3',
        'config' => ['bucket' => 'b', 'key' => 'AKIAEXAMPLE', 'secret' => 'SUPERSECRETVALUE', 'token' => 'TOKENSECRET1'],
    ]);

    $json = json_encode($mounts->present($mount));

    expect($json)->not->toContain('SUPERSECRETVALUE')->not->toContain('TOKENSECRET1')
        ->and($mounts->present($mount)['secrets_set']['secret'])->toBeTrue()
        ->and($mounts->present($mount)['config']['key'])->toBe('AKIAEXAMPLE')
        ->and($mount->config_enc)->not->toContain('SUPERSECRETVALUE');

    $mounts->update($mount, ['config' => ['bucket' => 'b2', 'key' => 'AKIAEXAMPLE', 'secret' => '']]);
    expect($mount->fresh()->credentials()['secret'])->toBe('SUPERSECRETVALUE')
        ->and($mount->fresh()->credentials()['bucket'])->toBe('b2');

    // The mask the form echoes back is also "unchanged".
    $mounts->update($mount, ['config' => ['bucket' => 'b2', 'secret' => Mounts::MASK]]);
    expect($mount->fresh()->credentials()['secret'])->toBe('SUPERSECRETVALUE');

    $mounts->update($mount, ['config' => ['bucket' => 'b2', 'secret' => 'ANOTHERSECRET']]);
    expect($mount->fresh()->credentials()['secret'])->toBe('ANOTHERSECRET');
});

it('scrubs secrets out of error messages', function () {
    amountUser(2);
    $mounts = amountMounts(2);
    $mount = $mounts->create(['name' => 'B', 'driver' => 's3', 'config' => ['bucket' => 'b', 'key' => 'K1', 'secret' => 'SUPERSECRETVALUE']]);

    expect(Mounts::redact('failed with SUPERSECRETVALUE in url', $mount))->toBe('failed with ' . Mounts::MASK . ' in url');
});

it('validates driver fields and refuses unknown drivers', function () {
    amountUser(2);

    $missing = amountCatch(fn () => amountMounts(2)->create(['name' => 'x', 'driver' => 's3', 'config' => []]));
    $unknown = amountCatch(fn () => amountMounts(2)->create(['name' => 'x', 'driver' => 'nope']));
    $site = amountCatch(fn () => amountMounts(2)->create(['name' => 'x', 'driver' => 'local', 'scope' => 'site', 'config' => ['root' => '/tmp']]));

    expect($missing->errors)->toHaveKey('bucket')
        ->and($unknown->error)->toBe('error_driver_unknown')
        ->and($site->status)->toBe(403);
});

it('resolves a spoken name to one mount, several candidates or none', function () {
    amountUser(2);
    amountMount(2, [], 'My Google Drive');
    amountMount(2, [], 'Work Dropbox');
    amountMount(2, [], 'Dropbox backup');
    $mounts = amountMounts(2);

    $google = $mounts->resolve('google drive');
    $dropbox = $mounts->resolve('my dropbox');
    $none = $mounts->resolve('onedrive');
    $exact = $mounts->resolve('work dropbox');

    expect($google['mount']->name)->toBe('My Google Drive')
        ->and($dropbox['mount'])->toBeNull()->and($dropbox['candidates'])->toHaveCount(2)
        ->and($none['mount'])->toBeNull()->and($none['candidates'])->toBe([])
        ->and($exact['mount']->name)->toBe('Work Dropbox');

    // No hint: unique only when there is a single mount.
    expect($mounts->resolve(null)['mount'])->toBeNull()->and($mounts->resolve(null)['candidates'])->toHaveCount(3);
});

it('refuses to delete a mount that rules use, unless forced', function () {
    $admin = amountAdmin(1);
    $mount = amountMount();
    amountSite(['assets/files/a.txt' => 'a']);
    $rule = amountRule($mount, $admin);
    FileEntry::query()->create(['rule_id' => $rule->getKey(), 'mount_id' => $mount->getKey(), 'path_hash' => sha1('assets/files/a.txt'), 'local_path' => 'assets/files/a.txt', 'remote_path' => 'a.txt']);

    expect(amountCatch(fn () => amountMounts(1)->delete($mount))->error)->toBe('error_mount_in_use');

    amountMounts(1)->delete($mount, true);

    expect(Mount::query()->count())->toBe(0)->and(Rule::query()->count())->toBe(0)->and(FileEntry::query()->count())->toBe(0);
});

it('tests a mount with a probe object and records the verdict', function () {
    amountUser(2);
    $mount = amountMount(2);
    $mount->status = Mount::STATUS_UNTESTED;
    $mount->save();

    $result = amountMounts(2)->test($mount);

    expect($result['ok'])->toBeTrue()->and($mount->fresh()->status)->toBe(Mount::STATUS_OK)
        ->and(amountRemoteFiles($mount))->toBe([]);

    $mount->setCredentials(['root' => AMOUNT_TEST_REMOTE . '/does/not/exist/and/cannot/be/read']);
    $mount->save();
    // A path that cannot be created on this system reports an error instead of throwing.
    $bad = amountMounts(2)->test($mount);
    expect($bad)->toHaveKeys(['ok', 'message']);
});

it('browses remote folders below the jail of a non-manager', function () {
    amountAdmin(1);
    amountUser(2);
    $mount = amountMount();
    mkdir(amountRemoteRoot($mount) . '/u2/reports', 0775, true);
    mkdir(amountRemoteRoot($mount) . '/u3/secret', 0775, true);

    $listing = amountMounts(2)->browse($mount, '');
    $admin = amountMounts(1)->browse($mount, '');

    expect(array_column($listing['folders'], 'name'))->toBe(['reports'])
        ->and(array_column($admin['folders'], 'name'))->toBe(['u2', 'u3'])
        ->and(amountCatch(fn () => amountMounts(2)->browse($mount, '../u3'))->error)->toBe('error_path_invalid');
});

it('shows the cheap index totals and the stored usage next to a mount and a rule', function () {
    amountUser(2);
    amountUser(3);
    $mount = amountMount();
    amountSite(['assets/files/a.txt' => 'a']);
    $mine = amountRule($mount, 2);
    $theirs = amountRule($mount, 3, 'assets/files', ['name' => 'Theirs']);
    $entry = fn ($rule, $hash, $size, $status) => FileEntry::query()->create([
        'rule_id' => $rule->getKey(), 'mount_id' => $mount->getKey(), 'path_hash' => $hash, 'local_path' => $hash,
        'remote_path' => $hash, 'size' => $size, 'status' => $status,
    ]);
    $entry($mine, 'a', 100, FileEntry::UPLOADED);
    $entry($mine, 'b', 50, FileEntry::UPLOADED);
    $entry($mine, 'c', 999, FileEntry::PENDING);
    $entry($theirs, 'd', 7000, FileEntry::UPLOADED);
    $mount->update(['usage_json' => ['bytes' => 123456, 'files' => 9, 'measured_at' => '2026-10-16T10:00:00Z', 'rows' => []]]);

    $files = new \Elcreator\aMount\Services\Files(Actor::for(2));
    $totals = $files->indexTotals();

    expect($totals['bytes'])->toBe(150)->and($totals['files'])->toBe(2)->and($totals['by_status'])->toBe(['pending' => 1, 'uploaded' => 2])
        ->and($files->indexTotals($mine)['bytes'])->toBe(150)
        ->and($files->indexTotals($theirs)['bytes'])->toBe(0)
        ->and($files->indexTotals(null, $mount)['files'])->toBe(2);

    $present = amountMounts(2)->present($mount->fresh());
    expect($present['index']['bytes'])->toBe(150)->and($present['usage']['bytes'])->toBe(123456);

    $rule = (new \Elcreator\aMount\Services\Rules(Actor::for(2)))->present($mine);
    expect($rule['index']['files'])->toBe(2)->and($rule['usage'])->toBeNull();
});
