<?php

use Elcreator\aMount\Listeners\NotFound;
use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Services\Actor;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Serve;
use Elcreator\aMount\Services\ServerConfig;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(fn () => amountReset());

/**
 * An uploaded file as the Syncer leaves it: the object exists on the (local
 * driver) store, the index row says where, and the local copy may be gone.
 */
function amountServeEntry(Mount $mount, Rule $rule, string $local = 'assets/files/pic.txt', string $content = '0123456789', array $attributes = []): FileEntry
{
    $remote = $attributes['remote_path'] ?? basename($local);
    $absolute = amountRemoteRoot($mount) . '/' . $remote;
    amountEnsureDir(dirname($absolute));
    file_put_contents($absolute, $content);

    return FileEntry::query()->create($attributes + [
        'rule_id' => $rule->getKey(),
        'mount_id' => $mount->getKey(),
        'path_hash' => sha1($local),
        'local_path' => $local,
        'remote_path' => $remote,
        'size' => strlen($content),
        'mtime' => 1700000000,
        'visibility' => Rule::PUBLIC,
        'status' => FileEntry::UPLOADED,
        'uploaded_at' => \Illuminate\Support\Carbon::now(),
    ]);
}

function amountBody(\Symfony\Component\HttpFoundation\Response $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

function amountPublicSetup(array $mountOptions = [], array $rule = []): array
{
    $admin = amountAdmin(1);
    $mount = amountMount(null, $mountOptions);
    $rule = amountRule($mount, $admin, 'assets/files', ['visibility' => Rule::PUBLIC] + $rule);
    $entry = amountServeEntry($mount, $rule);

    return [$admin, $mount, $rule, $entry];
}

it('finds only uploaded, public entries of an enabled rule', function () {
    [, $mount, $rule, $entry] = amountPublicSetup();

    expect(Serve::find('assets/files/pic.txt')?->getKey())->toBe($entry->getKey());

    $entry->update(['status' => FileEntry::PENDING]);
    expect(Serve::find('assets/files/pic.txt'))->toBeNull();
    $entry->update(['status' => FileEntry::UPLOADED]);

    $rule->update(['enabled' => false]);
    expect(Serve::find('assets/files/pic.txt'))->toBeNull();
    $rule->update(['enabled' => true]);

    $mount->update(['status' => Mount::STATUS_ERROR]);
    expect(Serve::find('assets/files/pic.txt'))->toBeNull();
    $mount->update(['status' => Mount::STATUS_OK]);

    // The rule turned private after the upload: the entry no longer counts as public.
    $rule->update(['visibility' => Rule::PRIVATE]);
    expect(Serve::find('assets/files/pic.txt'))->toBeNull();
});

it('never finds a private entry, nor anything unsafe', function () {
    $admin = amountAdmin(1);
    $mount = amountMount();
    $rule = amountRule($mount, $admin, 'assets/files', ['visibility' => Rule::PRIVATE]);
    amountServeEntry($mount, $rule, 'assets/files/backup.sql.gz', 'x', ['visibility' => Rule::PRIVATE]);
    $public = amountRule($mount, $admin, 'assets/files', ['visibility' => Rule::PUBLIC, 'name' => 'pub']);
    amountServeEntry($mount, $public, 'assets/files/shell.php', '<?php', []);
    amountServeEntry($mount, $public, 'assets/files/.env', 'KEY=1', ['remote_path' => 'env']);

    expect(Serve::find('assets/files/backup.sql.gz'))->toBeNull()
        ->and(Serve::find('assets/files/shell.php'))->toBeNull()
        ->and(Serve::find('assets/files/.env'))->toBeNull()
        ->and(Serve::find('assets/files/../../core/config.php'))->toBeNull()
        ->and(Serve::find(''))->toBeNull();

    AmountTestCore::config()->set('cms.settings.aMount.serve.enabled', false);
    expect(Serve::find('assets/files/shell.php'))->toBeNull();
});

it('redirects to the public URL of the mount and proxies otherwise', function () {
    [, $mount, , $entry] = amountPublicSetup(['public_url' => 'https://cdn.example/bucket/']);

    $redirect = Serve::publicResponse($entry->fresh());
    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($redirect->getTargetUrl())->toBe('https://cdn.example/bucket/pic.txt');

    // Forced proxy wins over a public URL.
    AmountTestCore::config()->set('cms.settings.aMount.serve.mode', 'proxy');
    $proxy = Serve::publicResponse($entry->fresh());
    expect($proxy)->toBeInstanceOf(StreamedResponse::class)->and(amountBody($proxy))->toBe('0123456789');

    // And so does the mount's own setting.
    AmountTestCore::config()->set('cms.settings.aMount.serve.mode', 'auto');
    $mount->update(['options_json' => ['public_url' => 'https://cdn.example', 'serve' => 'proxy']]);
    expect(Serve::publicResponse($entry->fresh()->load('mount')))->toBeInstanceOf(StreamedResponse::class);
});

it('streams through the site when the mount has no public URL', function () {
    [, , , $entry] = amountPublicSetup();

    $response = Serve::publicResponse($entry);

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Length'))->toBe('10')
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->headers->get('Content-Type'))->toBe('text/plain')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('public')
        ->and(amountBody($response))->toBe('0123456789');
});

it('honours byte ranges', function () {
    [, , , $entry] = amountPublicSetup();
    $ask = fn (string $range) => Serve::publicResponse($entry, Request::create('/', 'GET', [], [], [], ['HTTP_RANGE' => $range]));

    $middle = $ask('bytes=2-4');
    $tail = $ask('bytes=7-');
    $suffix = $ask('bytes=-3');
    $beyond = $ask('bytes=50-60');

    expect($middle->getStatusCode())->toBe(206)
        ->and($middle->headers->get('Content-Range'))->toBe('bytes 2-4/10')
        ->and($middle->headers->get('Content-Length'))->toBe('3')
        ->and(amountBody($middle))->toBe('234')
        ->and(amountBody($tail))->toBe('789')
        ->and(amountBody($suffix))->toBe('789')
        ->and($beyond->getStatusCode())->toBe(416)
        ->and($beyond->headers->get('Content-Range'))->toBe('bytes */10');

    expect(Serve::range('bytes=0-0', 10))->toBe([0, 0])
        ->and(Serve::range('bytes=5-2', 10))->toBeFalse()
        ->and(Serve::range('bytes=0-1,4-5', 10))->toBeNull()
        ->and(Serve::range('items=1-2', 10))->toBeNull();
});

it('sends an ETag from size and mtime and answers conditional requests', function () {
    [, , , $entry] = amountPublicSetup();

    $first = Serve::publicResponse($entry);
    $etag = $first->headers->get('ETag');
    $conditional = Serve::publicResponse($entry, Request::create('/', 'GET', [], [], [], ['HTTP_IF_NONE_MATCH' => $etag]));
    $head = Serve::publicResponse($entry, Request::create('/', 'HEAD'));

    expect($etag)->toBe('"' . dechex(10) . '-' . dechex(1700000000) . '"')
        ->and($conditional->getStatusCode())->toBe(304)
        ->and($head->headers->get('Content-Length'))->toBe('10')
        ->and($head->getContent())->toBe('');

    $entry->update(['size' => 11]);
    expect(Serve::publicResponse($entry->fresh())->headers->get('ETag'))->not->toBe($etag);
});

it('answers 404 when the object vanished from the store', function () {
    [, $mount, , $entry] = amountPublicSetup();
    unlink(amountRemoteRoot($mount) . '/pic.txt');

    expect(Serve::publicResponse($entry)->getStatusCode())->toBe(404);
});

it('serves a private entry only to a manager who owns it and can see the original', function () {
    amountAdmin(1);
    amountUser(2, ['amount', 'file_manager']);
    amountUser(3, ['amount', 'file_manager']);
    amountUser(4, ['amount', 'amount_manage', 'file_manager']);
    $mount = amountMount();
    $rule = amountRule($mount, 2, 'assets/files', ['visibility' => Rule::PRIVATE]);
    $entry = amountServeEntry($mount, $rule, 'assets/files/dump.sql', 'SELECT 1;', ['visibility' => Rule::PRIVATE]);

    $owner = Serve::privateResponse($entry, Actor::for(2));
    expect($owner->headers->get('Content-Disposition'))->toContain('attachment; filename="dump.sql"')
        ->and($owner->headers->get('Cache-Control'))->toContain('no-store')
        ->and(amountBody($owner))->toBe('SELECT 1;')
        ->and(amountBody(Serve::privateResponse($entry, Actor::for(4))))->toBe('SELECT 1;');

    // Somebody else's rule, and no such thing as a public route to it.
    expect(fn () => Serve::privateResponse($entry, Actor::for(3)))->toThrow(AmountException::class)
        ->and(Serve::find('assets/files/dump.sql'))->toBeNull();

    // The owner loses sight of the folder: no download either.
    amountRestrict('assets/files', 7);
    expect(fn () => Serve::privateResponse($entry, Actor::for(2)))->toThrow(AmountException::class);
});

it('refuses a private download once the owner loses the permission', function () {
    amountUser(2, ['amount', 'file_manager']);
    $mount = amountMount();
    $rule = amountRule($mount, 2, 'assets/files', ['visibility' => Rule::PRIVATE]);
    $entry = amountServeEntry($mount, $rule, 'assets/files/dump.sql', 'x', ['visibility' => Rule::PRIVATE]);

    amountRevoke(2, 'amount');

    expect(fn () => Serve::privateResponse($entry, Actor::for(2)))->toThrow(AmountException::class);
});

it('answers requests for missing public files from the NotFound listener, but nothing private', function () {
    [$admin, $mount, , $entry] = amountPublicSetup();
    $private = amountRule($mount, $admin, 'assets/files', ['visibility' => Rule::PRIVATE, 'name' => 'priv']);
    amountServeEntry($mount, $private, 'assets/files/secret.zip', 'zip', ['visibility' => Rule::PRIVATE]);
    $listener = new NotFound();
    $get = fn (string $uri, string $base = '/') => $listener->response($uri, Request::create($uri), $base);

    expect(amountBody($get('/assets/files/pic.txt?v=3')))->toBe('0123456789')
        ->and(amountBody($get('/site/assets/files/pic.txt', '/site/')))->toBe('0123456789')
        ->and($get('/assets/files/secret.zip'))->toBeNull()
        ->and($get('/assets/files/unknown.txt'))->toBeNull()
        ->and($get('/assets/files/%2e%2e/pic.txt'))->toBeNull()
        ->and($get('/other/assets/files/pic.txt', '/site/'))->toBeNull()
        ->and($listener->response('/assets/files/pic.txt', Request::create('/assets/files/pic.txt', 'POST'), '/'))->toBeNull();
});

it('answers the probe path only with the right token', function () {
    amountAdmin(1);
    $listener = new NotFound();
    $token = ServerConfig::probeToken();

    $ok = $listener->response("/assets/amount-probe-{$token}.txt", Request::create('/'), '/');
    $bad = $listener->response('/assets/amount-probe-' . str_repeat('a', 24) . '.txt', Request::create('/'), '/');

    expect($ok->getStatusCode())->toBe(200)->and($ok->headers->get('X-aMount'))->toBe('ok')
        ->and($bad)->toBeNull();

    // With no token ever created the listener does not invent one.
    \Illuminate\Support\Facades\DB::table('amount_state')->delete();
    expect($listener->response("/assets/amount-probe-{$token}.txt", Request::create('/'), '/'))->toBeNull()
        ->and(ServerConfig::probeToken(false))->toBeNull();
});
