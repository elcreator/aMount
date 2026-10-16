<?php

use Elcreator\aMount\Http\Controllers\BootstrapController;
use Elcreator\aMount\Http\Controllers\FileController;
use Elcreator\aMount\Http\Controllers\LocalController;
use Elcreator\aMount\Http\Controllers\MountController;
use Elcreator\aMount\Http\Controllers\RuleController;
use Elcreator\aMount\Http\Controllers\RunController;
use Elcreator\aMount\Http\Controllers\ServerController;
use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Run;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;

beforeEach(function () {
    amountReset();

    $container = Container::getInstance();
    // `response()->json()` needs no views or redirects: skip the constructor.
    $container->instance(ResponseFactoryContract::class, (new ReflectionClass(ResponseFactory::class))->newInstanceWithoutConstructor());
});

/** Sign in as a user for the controllers (the CMS session). */
function amountAs(int $userId): void
{
    AmountTestCore::$loginUserId = $userId;
}

function amountGet(array $query = []): Request
{
    return Request::create('/x', 'GET', $query);
}

function amountPost(array $input = []): Request
{
    return Request::create('/x', 'POST', $input);
}

/** @return array{0:int, 1:array} */
function amountAnswer(JsonResponse $response): array
{
    return [$response->getStatusCode(), $response->getData(true)];
}

it('answers 403 JSON to somebody without the amount permission, and to nobody at all', function () {
    amountUser(2, ['file_manager']);

    foreach ([2, 0] as $id) {
        amountAs($id);

        foreach ([
            fn () => (new BootstrapController())->show(),
            fn () => (new MountController())->browse(amountGet(), 'x'),
            fn () => (new LocalController())->folders(amountGet()),
            fn () => (new RuleController())->index(),
            fn () => (new FileController())->index(amountGet()),
            fn () => (new RunController())->index(amountGet()),
        ] as $call) {
            [$status, $body] = amountAnswer($call());

            expect($status)->toBe(403)->and($body['ok'])->toBeFalse()->and($body['error'])->toBe('forbidden')
                ->and($body['message'])->toBe('You are not allowed to do this.');
        }
    }
});

it('refuses the server endpoints to a manager without amount_manage', function () {
    amountUser(2, ['amount', 'file_manager']);
    amountAs(2);

    foreach ([
        fn () => (new ServerController())->show(),
        fn () => (new ServerController())->write(amountPost(['server' => 'nginx'])),
        fn () => (new ServerController())->probe(amountPost(['dir' => 'assets'])),
    ] as $call) {
        expect(amountAnswer($call())[0])->toBe(403);
    }
});

it('serves the server status to an administrator', function () {
    amountAdmin(1);
    amountAs(1);

    [$status, $body] = amountAnswer((new ServerController())->show());

    expect($status)->toBe(200)->and($body['ok'])->toBeTrue()->and($body['server'])->toHaveKeys(['server', 'directories', 'drift', 'instructions']);
});

it('bootstraps the page with everything it needs and no secret', function () {
    amountUser(2, ['amount', 'file_manager']);
    amountAs(2);

    $mount = new Mount(['uuid' => Mount::newUuid(), 'name' => 'My S3', 'driver' => 's3', 'owner_id' => 2, 'options_json' => [], 'status' => Mount::STATUS_OK]);
    $mount->setCredentials(['bucket' => 'b', 'secret' => 'TOP-SECRET-VALUE']);
    $mount->save();
    amountSite(['assets/files/a.txt' => 'a']);

    $response = (new BootstrapController())->show();
    [$status, $body] = amountAnswer($response);

    expect($status)->toBe(200)
        ->and($body)->toHaveKeys(['drivers', 'mounts', 'rules', 'presets', 'queue', 'server', 'flags', 'groups', 'limits', 'csrf_token', 'runs'])
        ->and($body['csrf_token'])->toBeString()
        ->and($body['flags'])->toBe(['may_manage' => false, 'is_admin' => false, 'use_udperms' => true, 'serve_enabled' => true])
        ->and($body['server'])->toBeNull()
        ->and($body['groups'])->toBe([])
        ->and($body['mounts'])->toHaveCount(1)
        ->and($body['mounts'][0]['config']['secret'])->toBe('********')
        ->and($body['drivers'][0])->toHaveKeys(['id', 'label', 'fields', 'description_text'])
        ->and($body['drivers'][0]['fields'][0])->toHaveKey('label_text')
        ->and($response->getContent())->not->toContain('TOP-SECRET-VALUE');
});

it('bootstraps an administrator with the server status and user groups', function () {
    amountAdmin(1);
    Illuminate\Database\Capsule\Manager::schema()->dropIfExists('membergroup_names');
    Illuminate\Database\Capsule\Manager::schema()->create('membergroup_names', function ($table) {
        $table->increments('id');
        $table->string('name');
    });
    Illuminate\Database\Capsule\Manager::table('membergroup_names')->insert(['name' => 'Editors']);
    amountAs(1);

    [, $body] = amountAnswer((new BootstrapController())->show());

    expect($body['flags']['may_manage'])->toBeTrue()->and($body['flags']['is_admin'])->toBeTrue()
        ->and($body['server'])->toBeArray()
        ->and($body['groups'])->toBe([['id' => 1, 'name' => 'Editors']]);

    Illuminate\Database\Capsule\Manager::schema()->dropIfExists('membergroup_names');
});

it('never lets a manager browse, test or delete through another manager\'s personal mount', function () {
    amountAdmin(1);
    amountUser(2);
    amountUser(3);
    $mount = amountMount(2, [], 'Private drive');
    amountEnsureDir(amountRemoteRoot($mount) . '/photos');

    amountAs(3);
    foreach ([
        fn () => (new MountController())->browse(amountGet(), $mount->uuid),
        fn () => (new MountController())->test($mount->uuid),
        fn () => (new MountController())->measure(amountPost(), $mount->uuid),
        fn () => (new MountController())->update(amountPost(['name' => 'mine now']), $mount->uuid),
    ] as $call) {
        [$status, $body] = amountAnswer($call());
        expect($status)->toBe(404)->and($body['error'])->toBe('not_found');
    }

    // Not even an administrator opens it.
    amountAs(1);
    expect(amountAnswer((new MountController())->browse(amountGet(), $mount->uuid))[0])->toBe(404);

    amountAs(2);
    [$status, $body] = amountAnswer((new MountController())->browse(amountGet(), $mount->uuid));
    expect($status)->toBe(200)->and(array_column($body['folders'], 'name'))->toBe(['photos']);
});

it('creates and updates a mount without ever returning the secret', function () {
    amountUser(2);
    amountAs(2);

    [$status, $body] = amountAnswer((new MountController())->store(amountPost([
        'name' => 'Bucket', 'driver' => 's3', 'config' => ['bucket' => 'b', 'secret' => 'SHHH-1'],
    ])));

    expect($status)->toBe(200)->and($body['mount']['config']['secret'])->toBe('********')
        ->and(json_encode($body))->not->toContain('SHHH-1');

    $uuid = $body['mount']['uuid'];
    [$status, $body] = amountAnswer((new MountController())->update(amountPost(['config' => ['bucket' => 'b2', 'secret' => '']]), $uuid));

    expect($status)->toBe(200)->and($body['mount']['config']['bucket'])->toBe('b2')->and($body['mount']['secrets_set']['secret'])->toBeTrue()
        ->and(Mount::query()->where('uuid', $uuid)->first()->credentials()['secret'])->toBe('SHHH-1');
});

it('refuses hostile input types instead of failing', function () {
    amountUser(2);
    amountAs(2);

    [$status, $body] = amountAnswer((new MountController())->store(amountPost(['name' => ['x'], 'driver' => ['s3']])));

    expect($status)->toBe(422)->and($body['ok'])->toBeFalse();
});

it('lists only the folders a manager may see, and says why a path is refused', function () {
    amountUser(2, ['amount', 'file_manager']);
    amountSite(['assets/files/a/x.txt' => 'x', 'assets/backup/dump.sql' => 'sql', 'assets/images/i.png' => 'p']);
    amountAs(2);

    [$status, $body] = amountAnswer((new LocalController())->folders(amountGet(['path' => 'assets'])));
    $names = array_column($body['folders'], 'name');

    expect($status)->toBe(200)->and($names)->toContain('files', 'images')->not->toContain('backup')
        ->and($body['path'])->toBe('assets')->and($body['parent'])->toBe('')->and($body['selectable'])->toBeTrue();

    $files = collect($body['folders'])->firstWhere('name', 'files');
    expect($files['hasChildren'])->toBeTrue()
        ->and(collect($body['folders'])->firstWhere('name', 'images')['hasChildren'])->toBeFalse();

    $check = fn (string $path) => amountAnswer((new LocalController())->check(amountGet(['path' => $path])))[1];

    expect($check('assets/files')['offloadable'])->toBeTrue()
        ->and($check('assets/files/a/x.txt'))->toMatchArray(['offloadable' => true, 'type' => 'file'])
        ->and($check('assets/backup'))->toMatchArray(['offloadable' => false, 'reason' => 'protected'])
        ->and($check('assets/files/nothing'))->toMatchArray(['offloadable' => false, 'reason' => 'missing'])
        ->and($check('assets/../core'))->toMatchArray(['offloadable' => false, 'reason' => 'invalid'])
        ->and(amountAnswer((new LocalController())->folders(amountGet(['path' => 'a/../b'])))[0])->toBe(422);
});

it('does not say whether a place outside the manager\'s root exists', function () {
    amountUser(2, ['amount', 'file_manager'], [], null, 'assets/files');
    amountSite(['assets/files/a.txt' => 'a', 'secret/real.txt' => 's']);
    amountAs(2);

    $check = fn (string $path) => amountAnswer((new LocalController())->check(amountGet(['path' => $path])))[1];

    expect($check('secret/real.txt')['reason'])->toBe($check('secret/imaginary.txt')['reason'])
        ->and($check('secret/real.txt')['offloadable'])->toBeFalse();
});

it('validates a rule through the services and answers field errors with 422', function () {
    amountUser(2, ['amount', 'file_manager']);
    $mount = amountMount(2);
    amountSite(['assets/files/a.txt' => 'a', 'assets/backup/dump.sql' => 'sql']);
    amountAs(2);

    [$status, $body] = amountAnswer((new RuleController())->store(amountPost(['mount' => $mount->uuid, 'local_path' => 'assets/backup'])));
    expect($status)->toBe(422)->and($body['errors'])->toHaveKey('local_path');

    [$status, $body] = amountAnswer((new RuleController())->store(amountPost(['mount' => $mount->uuid, 'local_path' => 'assets/files', 'name' => 'Files'])));
    expect($status)->toBe(200)->and($body['rule'])->toMatchArray(['name' => 'Files', 'local_path' => 'assets/files', 'owner_id' => 2]);

    [$status, $body] = amountAnswer((new RuleController())->index());
    expect($body['rules'])->toHaveCount(1)->and($body)->toHaveKey('presets');
});

it('keeps rules, runs and files private to their owner', function () {
    amountUser(2);
    amountUser(3);
    amountSite(['assets/files/a.txt' => 'a']);
    $mount = amountMount(2);
    $rule = amountRule($mount, 2);

    amountAs(2);
    [$status, $body] = amountAnswer((new RuleController())->run($rule->uuid));
    expect($status)->toBe(200)->and($body['run'])->toMatchArray(['rule' => $rule->uuid, 'direction' => 'up', 'status' => 'queued', 'active' => true]);
    $runUuid = $body['run']['uuid'];

    // A second start is a refusal, not a crash.
    expect(amountAnswer((new RuleController())->run($rule->uuid))[0])->toBe(409);

    amountAs(3);
    expect(amountAnswer((new RuleController())->run($rule->uuid))[0])->toBe(404)
        ->and(amountAnswer((new RuleController())->update(amountPost(['name' => 'x']), $rule->uuid))[0])->toBe(404)
        ->and(amountAnswer((new RuleController())->destroy($rule->uuid))[0])->toBe(404)
        ->and(amountAnswer((new RunController())->show($runUuid))[0])->toBe(404)
        ->and(amountAnswer((new RunController())->cancel($runUuid))[0])->toBe(404)
        ->and(amountAnswer((new RunController())->index(amountGet()))[1]['runs'])->toBe([])
        ->and(amountAnswer((new FileController())->index(amountGet(['rule' => $rule->uuid])))[0])->toBe(404);

    amountAs(2);
    expect(amountAnswer((new RunController())->index(amountGet(['active' => '1'])))[1]['runs'])->toHaveCount(1);

    [$status, $body] = amountAnswer((new RunController())->cancel($runUuid));
    expect($status)->toBe(200)->and($body['run']['status'])->toBe('cancelled')
        ->and(amountAnswer((new RunController())->index(amountGet(['active' => '1'])))[1]['runs'])->toBe([]);
});

it('lets an administrator see and cancel every run', function () {
    amountAdmin(1);
    amountUser(2);
    amountSite(['assets/files/a.txt' => 'a']);
    $run = amountRun(amountRule(amountMount(2), 2), 2);

    amountAs(1);

    expect(amountAnswer((new RunController())->show($run->uuid))[0])->toBe(200)
        ->and(amountAnswer((new RunController())->cancel($run->uuid))[1]['run']['status'])->toBe('cancelled');
});

it('lists, recalls and deletes files only for their owner', function () {
    amountUser(2);
    amountUser(3);
    amountSite(['assets/files/a.txt' => 'a']);
    $rule = amountRule(amountMount(2), 2);
    $run = amountSync($rule);
    $entry = amountEntry($rule, 'assets/files/a.txt');

    expect($run->status)->toBe(Run::DONE)->and($entry->status)->toBe(FileEntry::UPLOADED);

    amountAs(3);
    expect(amountAnswer((new FileController())->index(amountGet()))[1]['total'])->toBe(0)
        ->and(amountAnswer((new FileController())->recall(amountPost(['ids' => [$entry->id], 'overwrite' => '1'])))[0])->toBe(404)
        ->and(amountAnswer((new FileController())->destroy($entry->id))[0])->toBe(404)
        ->and((new FileController())->download(amountGet(), $entry->id)->getStatusCode())->toBe(404);

    amountAs(2);
    [, $list] = amountAnswer((new FileController())->index(amountGet(['rule' => $rule->uuid])));
    expect($list['total'])->toBe(1)->and($list['data'][0]['local_path'])->toBe('assets/files/a.txt');

    unlink(amountSitePath('assets/files/a.txt'));
    [$status, $body] = amountAnswer((new FileController())->recall(amountPost(['ids' => [$entry->id], 'to' => ''])));
    expect($status)->toBe(200)->and($body['run']['direction'])->toBe('down');

    $other = amountAnswer((new FileController())->recall(amountPost(['ids' => 'not-a-list'])));
    expect($other[0])->toBeIn([409, 422]);

    expect(amountAnswer((new FileController())->destroy($entry->id))[0])->toBe(200)
        ->and(FileEntry::query()->count())->toBe(0);
});
