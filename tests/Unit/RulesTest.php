<?php

use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Services\Actor;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Mounts;
use Elcreator\aMount\Services\Rules;
use Elcreator\aMount\Support\Principal;

beforeEach(fn () => amountReset());

function amountRules(int $userId): Rules
{
    return new Rules(Actor::for($userId));
}

/** The field errors a rule input produces, or [] when it is accepted. */
function amountRuleErrors(int $userId, array $input): array
{
    try {
        amountRules($userId)->validate($input);
    } catch (AmountException $e) {
        return $e->errors ?: ['_' => $e->error];
    }

    return [];
}

it('accepts a rule for a folder the manager can see and normalises it', function () {
    amountUser(2, ['amount', 'file_manager']);
    $mount = amountMount();
    amountSite(['assets/files/a.txt' => 'a']);

    $rule = amountRules(2)->create(['mount' => $mount->uuid, 'local_path' => '/assets/files/', 'include' => "*.txt\n*.zip", 'interval_minutes' => 60]);

    expect($rule->local_path)->toBe('assets/files')
        ->and($rule->owner_id)->toBe(2)
        ->and($rule->includes())->toBe(['*.txt', '*.zip'])
        ->and($rule->ephemeral)->toBeFalse()
        ->and($rule->next_run_at)->not->toBeNull()
        ->and($rule->name)->toContain('files');
});

it('refuses paths that do not exist or are not clean', function () {
    amountUser(2);
    $mount = amountMount();

    expect(amountRuleErrors(2, ['mount' => $mount->uuid, 'local_path' => 'assets/nope']))->toHaveKey('local_path')
        ->and(amountRuleErrors(2, ['mount' => $mount->uuid, 'local_path' => 'assets/../core']))->toHaveKey('local_path')
        ->and(amountRuleErrors(2, ['mount' => $mount->uuid, 'local_path' => '']))->toHaveKey('local_path');
});

it('refuses folders outside the manager visible scope', function () {
    amountUser(2, ['amount', 'file_manager'], [], null, 'assets/files');
    $mount = amountMount();
    amountSite(['assets/other/x.txt' => 'x', 'assets/files/ok.txt' => 'ok']);

    expect(amountRuleErrors(2, ['mount' => $mount->uuid, 'local_path' => 'assets/other']))->toHaveKey('local_path')
        ->and(amountRuleErrors(2, ['mount' => $mount->uuid, 'local_path' => 'assets/files']))->toBe([]);
});

it('refuses folders restricted to a document group the manager is not in', function () {
    amountUser(2, ['amount', 'file_manager'], [1]);
    $mount = amountMount();
    amountSite(['assets/files/secret/x.txt' => 'x', 'assets/files/open/y.txt' => 'y']);
    amountRestrict('assets/files/secret', 9);

    expect(amountRuleErrors(2, ['mount' => $mount->uuid, 'local_path' => 'assets/files/secret']))->toHaveKey('local_path')
        ->and(amountRuleErrors(2, ['mount' => $mount->uuid, 'local_path' => 'assets/files/open']))->toBe([]);
});

it('refuses protected folders without their permission', function () {
    amountUser(2, ['amount', 'file_manager']);
    amountUser(3, ['amount', 'bk_manager']);
    $mount = amountMount();
    amountSite(['assets/backup/dump.sql' => 'sql']);

    expect(amountRuleErrors(2, ['mount' => $mount->uuid, 'local_path' => 'assets/backup']))->toHaveKey('local_path')
        ->and(amountRuleErrors(3, ['mount' => $mount->uuid, 'local_path' => 'assets/backup']))->toBe([]);
});

it('needs file_manager to move files or mirror deletions', function () {
    amountUser(2, ['amount', 'file_manager']);
    amountUser(3, ['amount', 'bk_manager']);   // may see backups, may not modify files
    $mount = amountMount();
    amountSite(['assets/backup/dump.sql' => 'sql', 'assets/files/a.txt' => 'a']);

    expect(amountRuleErrors(3, ['mount' => $mount->uuid, 'local_path' => 'assets/backup', 'mode' => 'move']))->toHaveKey('mode')
        ->and(amountRuleErrors(3, ['mount' => $mount->uuid, 'local_path' => 'assets/backup', 'mirror_deletes' => true]))->toHaveKey('mode')
        ->and(amountRuleErrors(3, ['mount' => $mount->uuid, 'local_path' => 'assets/backup', 'mode' => 'copy']))->toBe([])
        ->and(amountRuleErrors(2, ['mount' => $mount->uuid, 'local_path' => 'assets/files', 'mode' => 'move']))->toBe([])
        ->and(amountRuleErrors(2, ['mount' => $mount->uuid, 'local_path' => 'assets/files', 'mode' => 'teleport']))->toHaveKey('mode');
});

it('only publishes locations a web server serves', function () {
    amountAdmin(1);
    $mount = amountMount();
    amountSite(['assets/cache/x.txt' => 'x', 'core/custom/y.txt' => 'y', 'assets/files/.hidden/z.txt' => 'z', 'assets/files/ok.txt' => 'ok']);

    foreach (['assets/cache', 'core/custom', 'assets/files/.hidden'] as $path) {
        expect(amountRuleErrors(1, ['mount' => $mount->uuid, 'local_path' => $path, 'visibility' => 'public']))->toHaveKey('visibility');
    }

    expect(amountRuleErrors(1, ['mount' => $mount->uuid, 'local_path' => 'assets/files', 'visibility' => 'public']))->toBe([])
        ->and(amountRuleErrors(1, ['mount' => $mount->uuid, 'local_path' => 'assets/files', 'visibility' => 'secret']))->toHaveKey('visibility');
});

it('accepts only a manual schedule or at least five minutes', function () {
    amountUser(2);
    $mount = amountMount();
    $base = ['mount' => $mount->uuid, 'local_path' => 'assets/files'];

    expect(amountRuleErrors(2, $base + ['interval_minutes' => 3]))->toHaveKey('interval_minutes')
        ->and(amountRuleErrors(2, $base + ['interval_minutes' => -1]))->toHaveKey('interval_minutes')
        ->and(amountRuleErrors(2, $base + ['interval_minutes' => 0]))->toBe([])
        ->and(amountRuleErrors(2, $base + ['interval_minutes' => 5]))->toBe([])
        ->and(amountRuleErrors(2, $base + ['min_size' => 10, 'max_size' => 5]))->toHaveKey('max_size');
});

it('refuses a mount the manager may not use and a remote prefix that escapes', function () {
    amountUser(2);
    amountUser(3);
    $theirs = amountMount(3);
    $mine = amountMount();

    expect(amountRuleErrors(2, ['mount' => $theirs->uuid, 'local_path' => 'assets/files']))->toHaveKey('mount')
        ->and(amountRuleErrors(2, ['mount' => 'nonsense', 'local_path' => 'assets/files']))->toHaveKey('mount')
        ->and(amountRuleErrors(2, ['mount' => $mine->uuid, 'local_path' => 'assets/files', 'remote_prefix' => '../x']))->toHaveKey('remote_prefix');
});

it('jails managers without amount_manage under u<id>/ on a site mount', function () {
    amountAdmin(1);
    amountUser(2);
    amountUser(3, ['amount', 'amount_manage', 'file_manager']);
    $site = amountMount(null, ['root' => 'backups']);
    $personal = amountMount(2);

    expect(Mounts::remoteBase($site, Principal::forUser(2)))->toBe('backups/u2')
        ->and(Mounts::remoteBase($site, Principal::forUser(3)))->toBe('backups')
        ->and(Mounts::remoteBase($site, Principal::forUser(1)))->toBe('backups')
        ->and(Mounts::remoteBase($personal, Principal::forUser(2)))->toBe('');
});

it('lets only an administrator hand a rule to somebody else, who must be able to use the module', function () {
    amountUser(2);
    amountUser(3, ['amount', 'amount_manage', 'file_manager']);
    amountUser(4, ['amount', 'file_manager']);
    amountUser(5, ['file_manager']);
    $mount = amountMount();
    $base = ['mount' => $mount->uuid, 'local_path' => 'assets/files'];

    expect(fn () => amountRules(2)->validate($base + ['owner_id' => 4]))->toThrow(AmountException::class)
        ->and(amountRules(3)->create($base + ['owner_id' => 4])->owner_id)->toBe(4)
        ->and(amountRuleErrors(3, $base + ['owner_id' => 5]))->toHaveKey('owner_id');
});

it('lists, finds and deletes only the rules the manager owns', function () {
    amountUser(2);
    amountUser(3);
    amountUser(4, ['amount', 'amount_manage', 'file_manager']);
    $mount = amountMount();
    amountSite(['assets/files/a.txt' => 'a']);
    $mine = amountRule($mount, 2);
    $other = amountRule($mount, 3, 'assets/files', ['name' => 'Other']);
    FileEntry::query()->create(['rule_id' => $mine->getKey(), 'mount_id' => $mount->getKey(), 'path_hash' => sha1('x'), 'local_path' => 'x', 'remote_path' => 'x']);

    expect(amountRules(2)->list())->toHaveCount(1)
        ->and(amountRules(4)->list())->toHaveCount(2)
        ->and(fn () => amountRules(2)->find($other->uuid))->toThrow(AmountException::class)
        ->and(amountRules(4)->find($other->uuid)->uuid)->toBe($other->uuid);

    amountRules(2)->delete($mine);

    expect(Rule::query()->count())->toBe(1)->and(FileEntry::query()->count())->toBe(0);
});

it('suggests only folders the manager can see', function () {
    amountUser(2, ['amount', 'file_manager']);
    amountUser(3, ['amount', 'bk_manager', 'file_manager']);
    amountSite(['assets/images/a.png' => 'a', 'assets/backup/d.sql' => 'd']);

    $keys = fn (int $id) => array_column(amountRules($id)->presets(), 'key');

    // assets/files always exists in the harness; media does not.
    expect($keys(2))->toBe(['images', 'files'])
        ->and($keys(3))->toBe(['backups', 'images', 'files']);
});

it('updates a rule keeping unspecified fields and refuses a stranger', function () {
    amountUser(2);
    amountUser(3);
    $mount = amountMount();
    amountSite(['assets/files/a.txt' => 'a']);
    $rule = amountRules(2)->create(['mount' => $mount->uuid, 'local_path' => 'assets/files', 'retention_days' => 30, 'name' => 'Keep']);

    $updated = amountRules(2)->update($rule, ['mode' => 'move']);

    expect($updated->mode)->toBe('move')->and($updated->retention_days)->toBe(30)->and($updated->name)->toBe('Keep')
        ->and(fn () => amountRules(3)->update($rule, ['mode' => 'copy']))->toThrow(AmountException::class);
});
