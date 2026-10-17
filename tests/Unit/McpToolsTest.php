<?php

use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\Actor;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Delivery;

/**
 * The MCP tools are thin: protocol mapping in src/Mcp/Tools, behaviour in
 * Operations over Delivery. eMCP (and Laravel MCP) is not installed here, so
 * the behaviour is exercised through Operations with the identity stubbed the
 * way eMCP establishes it (the manager session, i.e. getLoginUserID), and the
 * protocol wiring is pinned by reading the sources.
 */

beforeEach(fn () => amountReset());

/** What AmountTool::handle does, minus the protocol: identity from the "session", then the operation. */
function amountMcp(int $sessionUser, string $operation, array $args = []): array
{
    AmountTestCore::$loginUserId = $sessionUser;
    $actor = Actor::current()->authorize();

    return Operations::$operation(Delivery::for($actor->userId()), $args);
}

function amountMcpError(int $sessionUser, string $operation, array $args = []): ?AmountException
{
    try {
        amountMcp($sessionUser, $operation, $args);
    } catch (AmountException $e) {
        return $e;
    }

    return null;
}

it('acts as the permitted manager and lists only their mounts', function () {
    amountUser(2);
    amountUser(3);
    amountMount(2, [], 'Mine');
    amountMount(3, [], 'Theirs');
    amountMount(null, [], 'Site store');

    $names = array_column(amountMcp(2, 'mounts')['mounts'], 'name');

    expect($names)->toContain('Mine', 'Site store')->not->toContain('Theirs');
});

it('refuses a manager without the amount permission and an anonymous call', function () {
    amountUser(4, ['file_manager']);
    amountMount();

    foreach ([4, 0, 99] as $session) {
        foreach (['mounts', 'runs', 'rules', 'cancel', 'status', 'deliver'] as $op) {
            expect(amountMcpError($session, $op, ['run' => 'x', 'path' => 'assets/a.txt'])?->error)->toBe('forbidden');
        }
    }
});

it('does not let an argument choose the acting user', function () {
    amountUser(2);
    amountUser(3);
    amountMount(3, [], 'Theirs');

    $out = amountMcp(2, 'mounts', ['user' => 3, 'user_id' => 3, 'owner_id' => 3]);

    // "theirs" is a stranger's personal mount: not found, as if it did not exist.
    expect(amountMcpError(2, 'mounts', ['name' => 'theirs', 'user_id' => 3])->error)->toBe('not_found');
    expect(array_column($out['mounts'], 'name'))->not->toContain('Theirs');
});

it('keeps a personal mount private from every tool, administrators included', function () {
    amountUser(2);
    amountAdmin(1);
    $mount = amountMount(2, [], 'Private drive');
    $rule = amountRule($mount, 2);
    $run = amountRun($rule, 2);
    $entry = FileEntry::query()->create([
        'rule_id' => $rule->getKey(), 'mount_id' => $mount->getKey(), 'path_hash' => sha1('a'), 'local_path' => 'assets/files/a.txt',
        'remote_path' => 'a.txt', 'size' => 1, 'mtime' => 1, 'visibility' => 'private', 'status' => FileEntry::UPLOADED,
    ]);

    foreach ([
        ['browse', ['mount' => $mount->uuid]],
        ['deliver', ['path' => 'assets/files/a.txt', 'mount' => $mount->uuid]],
        ['fetch', ['mount' => $mount->uuid, 'remote' => 'a.txt', 'to' => 'assets/files']],
        ['measure', ['mount' => $mount->uuid]],
        ['measure', ['rule' => $rule->uuid]],
        ['files', ['rule' => $rule->uuid]],
        ['status', ['run' => $run->uuid]],
        ['cancel', ['run' => $run->uuid]],
        ['recall', ['files' => [$entry->getKey()]]],
        ['deleteRemote', ['file' => $entry->getKey(), 'confirm' => true]],
        ['runs', ['rule' => $rule->uuid]],
    ] as [$op, $args]) {
        expect(amountMcpError(1, $op, $args)?->error)->toBeIn(['not_found', 'run_refused', 'forbidden'], $op);
    }

    expect(amountMcp(1, 'runs')['runs'])->toBe([])
        ->and(amountMcp(1, 'rules')['rules'])->toBe([])
        ->and(Run::query()->find($run->getKey())->status)->toBe(Run::QUEUED);

    // The owner sees all of it.
    expect(amountMcp(2, 'status', ['run' => $run->uuid])['run'])->toBe($run->uuid)
        ->and(amountMcp(2, 'rules')['rules'])->toHaveCount(1)
        ->and(amountMcp(2, 'files', ['rule' => $rule->uuid])['total'])->toBe(1);
});

it('refuses to deliver a file the manager cannot see in the file manager', function () {
    amountUser(2, ['amount']);
    amountMount(2);
    amountSite(['assets/files/a.txt' => 'a']);

    expect(amountMcpError(2, 'deliver', ['path' => 'assets/files/a.txt'])?->error)->toBeIn(['path_denied', 'modify_denied']);
    expect(Run::query()->count())->toBe(0);
});

it('delivers for a permitted manager and polls through status and runs', function () {
    amountUser(2);
    $mount = amountMount(2, [], 'Drive');
    amountSite(['assets/files/a.txt' => 'a']);

    $started = amountMcp(2, 'deliver', ['path' => 'assets/files/a.txt', 'mount' => 'drive', 'folder' => 'Pics']);
    $status = amountMcp(2, 'status', ['run' => $started['run']]);

    expect($started['mount'])->toBe($mount->uuid)
        ->and($status['status'])->toBe(Run::QUEUED)->and($status['finished'])->toBeFalse();
    expect(array_column(amountMcp(2, 'runs', ['active' => true])['runs'], 'run'))->toBe([$started['run']]);

    $cancelled = amountMcp(2, 'cancel', ['run' => $started['run']]);
    expect($cancelled['status'])->toBe(Run::CANCELLED)->and($cancelled['finished'])->toBeTrue();
    expect(amountMcp(2, 'runs', ['active' => true])['runs'])->toBe([]);
});

it('hides runs of other managers from a manager but not from an administrator', function () {
    amountUser(2);
    amountUser(3);
    amountAdmin(1);
    $mount = amountMount();
    $run = amountRun(amountRule($mount, 3), 3);

    expect(amountMcpError(2, 'status', ['run' => $run->uuid])->error)->toBe('not_found')
        ->and(amountMcpError(2, 'cancel', ['run' => $run->uuid])->error)->toBe('not_found')
        ->and(amountMcp(2, 'runs')['runs'])->toBe([])
        ->and(amountMcp(1, 'status', ['run' => $run->uuid])['run'])->toBe($run->uuid);
});

it('measures a site mount only for an administrator and reports the run through status', function () {
    amountUser(2);
    amountAdmin(1);
    $mount = amountMount(null, [], 'Site');

    expect(amountMcpError(2, 'measure', ['mount' => $mount->uuid])->error)->toBe('run_refused');

    $started = amountMcp(1, 'measure', ['mount' => $mount->uuid]);
    $status = amountMcp(1, 'status', ['run' => $started['run']]);

    expect($status['direction'])->toBe('size')->and($status['finished'])->toBeFalse();
});

it('asks which mount when it is ambiguous and lists the candidates in the message', function () {
    amountUser(2);
    amountMount(2, [], 'Alpha cloud');
    amountMount(2, [], 'Beta cloud');
    amountSite(['assets/files/a.txt' => 'a']);

    $e = amountMcpError(2, 'deliver', ['path' => 'assets/files/a.txt', 'mount' => 'cloud']);
    $text = Operations::explain($e);

    expect($e->error)->toBe('ambiguous_mount')
        ->and($text)->toContain('candidates=')->toContain('Alpha cloud')->toContain('Beta cloud')->toContain('"uuid"')
        ->and($text)->not->toContain('root');
});

it('requires arguments and an explicit confirmation for the destructive tools', function () {
    amountUser(2);
    $mount = amountMount(2);
    $rule = amountRule($mount, 2);
    $entry = FileEntry::query()->create([
        'rule_id' => $rule->getKey(), 'mount_id' => $mount->getKey(), 'path_hash' => sha1('a'), 'local_path' => 'assets/files/a.txt',
        'remote_path' => 'a.txt', 'size' => 1, 'mtime' => 1, 'visibility' => 'private', 'status' => FileEntry::PENDING,
    ]);

    expect(amountMcpError(2, 'status', [])->errors)->toHaveKey('run')
        ->and(amountMcpError(2, 'recall', ['files' => []])->errors)->toHaveKey('files')
        ->and(amountMcpError(2, 'deleteRemote', ['file' => $entry->getKey()])->error)->toBe('error_confirm_required')
        ->and(FileEntry::query()->count())->toBe(1);

    amountMcp(2, 'deleteRemote', ['file' => $entry->getKey(), 'confirm' => 'true']);

    expect(FileEntry::query()->count())->toBe(0);
});

it('bounds listings and never puts a credential into an output', function () {
    amountUser(2);
    $mount = new Mount(['uuid' => Mount::newUuid(), 'name' => 'Bucket', 'driver' => 's3', 'owner_id' => 2, 'options_json' => [], 'status' => Mount::STATUS_OK]);
    $mount->setCredentials(['key' => 'AKIAPUBLICID', 'secret' => 'TOPSECRET-VALUE-123', 'bucket' => 'b', 'region' => 'eu']);
    $mount->save();
    $rule = amountRule($mount, 2);

    for ($i = 0; $i < 60; $i++) {
        amountRun($rule, 2);
    }

    $out = [
        amountMcp(2, 'mounts'),
        amountMcp(2, 'mounts', ['name' => 'bucket']),
        amountMcp(2, 'rules'),
        amountMcp(2, 'runs'),
        amountMcp(2, 'files', ['rule' => $rule->uuid]),
    ];

    expect(count($out[3]['runs']))->toBe(Delivery::LIST_LIMIT);

    foreach ($out as $one) {
        $json = json_encode($one);
        expect($json)->not->toContain('TOPSECRET-VALUE-123')->not->toContain('AKIAPUBLICID');
    }
});

// ---------------------------------------------------------------------------
// Wiring that needs eMCP / Laravel MCP, pinned by reading the sources.
// ---------------------------------------------------------------------------

function amountMcpSources(): array
{
    $out = [];

    foreach (glob(dirname(__DIR__, 2) . '/src/Mcp/Tools/*.php') ?: [] as $file) {
        $out[basename($file, '.php')] = (string) file_get_contents($file);
    }

    return $out;
}

test('the provider registers every tool class', function () {
    $provider = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Mcp/ToolProvider.php');
    $sources = amountMcpSources();

    expect(count($sources))->toBeGreaterThanOrEqual(12);

    foreach (array_keys($sources) as $class) {
        expect($provider)->toContain($class . '::class');
    }
});

test('every tool that changes anything carries the eMCP write marker, and no read tool does', function () {
    $writes = ['DeliverTool', 'FetchTool', 'RecallTool', 'CancelTool', 'DeleteRemoteTool'];

    foreach (amountMcpSources() as $class => $code) {
        $marked = str_contains($code, 'implements WritesSite') && str_contains($code, 'EvolutionCMS\eMCP\Contracts\WritesSite');
        expect($marked)->toBe(in_array($class, $writes, true), $class);
    }
});

test('no tool takes a user from the agent, and identity comes from the manager session only', function () {
    foreach (amountMcpSources() as $class => $code) {
        expect($code)->not->toMatch('/[\'"](user|user_id|owner|owner_id|actor)[\'"]\s*=>/', $class);
    }

    $base = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Mcp/AmountTool.php');
    $ops = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Mcp/Operations.php');

    expect($base)->toContain('Actor::current()->authorize()')->not->toContain('$_SESSION')
        ->and($ops)->not->toContain('$_SESSION')->not->toContain('Actor::');
});

test('every tool documents its parameters and names itself', function () {
    foreach (amountMcpSources() as $class => $code) {
        expect($code)->toMatch("/#\\[Name\\('amount\\.[a-z_]+'\\)\\]/")->toContain('#[Description(');

        preg_match_all("/\\\$schema->(?:string|boolean|integer|array)\\(\\).*?;/s", $code, $fields);
        foreach ($fields[0] as $field) {
            expect(str_contains($field, '->description('))->toBeTrue($class);
        }
    }
});
