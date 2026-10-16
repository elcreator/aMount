<?php

use Elcreator\aMount\Support\Paths;
use Elcreator\aMount\Support\Principal;
use Elcreator\aMount\Support\Scope;

beforeEach(fn () => amountReset());

/** Site paths a scope walks below a folder. */
function amountVisible(Scope $scope, string $folder = 'assets'): array
{
    $found = [];

    foreach ($scope->files(Paths::absolute($folder), true) as $file) {
        $found[] = Paths::relative($file['path']);
    }

    sort($found);

    return $found;
}

/** A symlink, or on Windows without the privilege, a junction. */
function amountLink(string $target, string $link): bool
{
    set_error_handler(static fn () => true);
    $linked = symlink($target, $link);
    restore_error_handler();

    if ($linked) {
        return true;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        exec('cmd /c mklink /J "' . str_replace('/', '\\', $link) . '" "' . str_replace('/', '\\', $target) . '" 2>&1', $out, $code);

        if ($code === 0 && is_link($link)) {
            return true;
        }

        // PHP does not report this junction as a link, so the scanner could not
        // tell it apart either: not a usable fixture. Remove it.
        $code === 0 && @rmdir($link);
    }

    return false;
}

function amountTree(): void
{
    amountSite([
        'assets/files/a.txt' => 'a',
        'assets/files/secret/hidden.txt' => 'h',
        'assets/files/secret/deeper/more.txt' => 'm',
        'assets/backup/db.sql' => 'sql',
        'assets/snippets/s.php' => 'code',
    ]);
}

it('prunes folders protected by permission', function () {
    amountTree();
    $scope = Scope::for(Principal::forUser(amountUser(7, ['amount', 'file_manager'])));

    expect(amountVisible($scope))->toBe(['assets/files/a.txt', 'assets/files/secret/deeper/more.txt', 'assets/files/secret/hidden.txt'])
        ->and($scope->denyDirectory(Paths::absolute('assets/backup')))->toBe('protected')
        ->and($scope->denyDirectory(Paths::absolute('assets/snippets')))->toBe('protected');
});

it('opens a protected folder to the permission that guards it', function () {
    amountTree();
    $scope = Scope::for(Principal::forUser(amountUser(7, ['amount', 'file_manager', 'bk_manager'])));

    expect(amountVisible($scope))->toContain('assets/backup/db.sql')->not->toContain('assets/snippets/s.php');
});

it('lets a manager without file_manager see only the folder their permission opens', function () {
    amountTree();
    $scope = Scope::for(Principal::forUser(amountUser(7, ['amount', 'bk_manager'])));

    expect(amountVisible($scope, 'assets/backup'))->toBe(['assets/backup/db.sql'])
        ->and(amountVisible($scope))->toBe([])
        ->and($scope->denyDirectory(Paths::absolute('assets/files')))->toBe('no_file_manager');
});

it('hides a file_groups restricted subtree and everything below it', function () {
    amountTree();
    amountRestrict('assets/files/secret', 9);
    $scope = Scope::for(Principal::forUser(amountUser(7, ['amount', 'file_manager'])));

    expect(amountVisible($scope, 'assets/files'))->toBe(['assets/files/a.txt'])
        ->and($scope->denyDirectory(Paths::absolute('assets/files/secret')))->toBe('restricted')
        ->and($scope->denyFile(Paths::absolute('assets/files/secret/deeper/more.txt')))->toBe('restricted');
});

it('shows the restricted subtree to a member of the group and to an administrator', function () {
    amountTree();
    amountRestrict('assets/files/secret', 9);

    $member = Scope::for(Principal::forUser(amountUser(7, ['amount', 'file_manager'], [9])));
    $admin = Scope::for(Principal::forUser(amountAdmin()));

    expect(amountVisible($member, 'assets/files'))->toContain('assets/files/secret/hidden.txt')
        ->and(amountVisible($admin, 'assets/files'))->toContain('assets/files/secret/hidden.txt');
});

it('restricts a single file', function () {
    amountSite(['assets/files/a.txt' => 'a', 'assets/files/b.txt' => 'b']);
    amountRestrict('assets/files/b.txt', 9);
    $scope = Scope::for(Principal::forUser(amountUser(7, ['amount', 'file_manager'])));

    expect(amountVisible($scope, 'assets/files'))->toBe(['assets/files/a.txt']);
});

it('skips links', function () {
    amountSite(['assets/files/a.txt' => 'a', 'elsewhere/x.txt' => 'x']);

    if (!amountLink(amountSitePath('elsewhere'), amountSitePath('assets/files/linked'))) {
        $this->markTestSkipped('This system cannot create links.');
    }

    $scope = Scope::for(Principal::forUser(amountAdmin()));

    expect(amountVisible($scope, 'assets/files'))->toBe(['assets/files/a.txt'])
        ->and($scope->denyDirectory(Paths::absolute('assets/files/linked')))->toBe('link');
});

it('confines a user whose root is narrower than the site', function () {
    amountTree();
    $scope = Scope::for(Principal::forUser(amountUser(7, ['amount', 'file_manager'], [], null, 'assets/files')));

    expect(amountVisible($scope, 'assets/files'))->toContain('assets/files/a.txt')
        ->and($scope->denyDirectory(Paths::absolute('assets')))->toBe('outside_root')
        ->and(amountVisible($scope, 'assets'))->toBe([]);
});

it('stops seeing anything once amount-relevant access is revoked', function () {
    amountTree();
    $user = amountUser(7, ['amount', 'file_manager']);

    expect(Principal::forUser($user)->mayUse())->toBeTrue();

    amountRevoke($user, 'file_manager');
    $scope = Scope::for(Principal::forUser($user));

    expect($scope->denyDirectory(Paths::absolute('assets/files')))->toBe('no_file_manager')
        ->and(amountVisible($scope, 'assets/files'))->toBe([]);

    amountRevoke($user, 'amount');
    expect(Principal::forUser($user)->mayUse())->toBeFalse();
});

it('treats a blocked or unknown account as inactive', function () {
    amountTree();
    $user = amountUser(7);
    Illuminate\Database\Capsule\Manager::table('user_attributes')->where('internalKey', $user)->update(['blocked' => 1]);

    expect(Principal::forUser($user)->active)->toBeFalse()
        ->and(Scope::forUser($user)->denyDirectory(Paths::absolute('assets/files')))->toBe('inactive')
        ->and(Principal::forUser(999)->mayUse())->toBeFalse();
});

it('answers canModify and canCreateIn from file_manager', function () {
    amountTree();
    $editor = Scope::for(Principal::forUser(amountUser(7, ['amount', 'file_manager'])));
    $reader = Scope::for(Principal::forUser(amountUser(8, ['amount', 'bk_manager'])));

    expect($editor->canModify(Paths::absolute('assets/files/a.txt')))->toBeTrue()
        ->and($editor->canCreateIn(Paths::absolute('assets/files')))->toBeTrue()
        ->and($editor->canCreateIn(Paths::absolute('assets/backup')))->toBeFalse()
        ->and($reader->canModify(Paths::absolute('assets/backup/db.sql')))->toBeFalse()
        ->and($reader->canCreateIn(Paths::absolute('assets/backup')))->toBeFalse();
});
