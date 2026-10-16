<?php

use Elcreator\aMount\Http\Controllers\MountController;
use Elcreator\aMount\Http\Controllers\ServerController;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Services\Actor;
use Elcreator\aMount\Services\Mounts;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;

beforeEach(function () {
    amountReset();
    Container::getInstance()->instance(ResponseFactoryContract::class, (new ReflectionClass(ResponseFactory::class))->newInstanceWithoutConstructor());
});

function amountDraftRoot(): string
{
    static $n = 0;
    $root = AMOUNT_TEST_REMOTE . '/draft' . ++$n;
    amountEnsureDir($root);

    return $root;
}

it('tests an unsaved mount without storing anything', function () {
    amountUser(2);
    $root = amountDraftRoot();

    $result = (new Mounts(Actor::for(2)))->testDraft(['driver' => 'local', 'config' => ['root' => $root]]);

    expect(array_keys($result))->toBe(['ok', 'message'])
        ->and($result['ok'])->toBeTrue()
        ->and(Mount::query()->count())->toBe(0);

    // The probe object is cleaned up again.
    $left = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $left[] = $file->getPathname();
    }

    expect($left)->toBe([]);
});

it('reports a failing connection as a verdict, not an error', function () {
    amountUser(2);

    $result = (new Mounts(Actor::for(2)))->testDraft(['driver' => 'local', 'config' => ['root' => amountDraftRoot() . '/missing/../../nope-' . uniqid()]]);

    expect($result['ok'])->toBeBool()->and(Mount::query()->count())->toBe(0);
});

it('applies the driver validation and the access rules of a real save', function () {
    amountUser(2);
    amountAdmin(1);
    $mounts = new Mounts(Actor::for(2));

    $invalid = amountCatch(fn () => $mounts->testDraft(['driver' => 'local', 'config' => ['root' => '']]));
    $unknown = amountCatch(fn () => $mounts->testDraft(['driver' => 'nope']));
    $site = amountCatch(fn () => $mounts->testDraft(['driver' => 'local', 'scope' => 'site', 'config' => ['root' => amountDraftRoot()]]));

    expect($invalid->status)->toBe(422)->and($invalid->errors)->toHaveKey('root')
        ->and($unknown->error)->toBe('error_driver_unknown')
        ->and($site->status)->toBe(403)
        ->and(Mount::query()->count())->toBe(0);
});

it('keeps stored secrets for an edited mount and refuses somebody else\'s', function () {
    amountUser(2);
    amountUser(3);
    $mount = amountMount(2);

    // No config in the body: the stored one stands in.
    $own = (new Mounts(Actor::for(2)))->testDraft([], $mount->uuid);
    $stranger = amountCatch(fn () => (new Mounts(Actor::for(3)))->testDraft([], $mount->uuid));

    expect($own['ok'])->toBeTrue()
        ->and($stranger->status)->toBe(404);
});

it('answers the stateless test over HTTP with a result and no mount', function () {
    amountUser(2);
    AmountTestCore::$loginUserId = 2;

    $request = Request::create('/x', 'POST', ['driver' => 'local', 'config' => ['root' => amountDraftRoot()]]);
    $response = (new MountController())->testDraft($request);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)->and($body['ok'])->toBeTrue()
        ->and($body['result']['ok'])->toBeTrue()->and($body)->not->toHaveKey('mount')
        ->and(Mount::query()->count())->toBe(0);
});

it('lists a snippet for every web server on the server endpoint', function () {
    amountAdmin(1);
    AmountTestCore::$loginUserId = 1;

    $body = (new ServerController())->show()->getData(true);

    expect($body['snippets'])->toHaveKeys(['nginx', 'apache', 'frankenphp'])
        ->and($body['snippets']['nginx'])->toBeString()->not->toBe('')
        ->and($body['snippets']['frankenphp'])->toContain('nothing to change');
});
