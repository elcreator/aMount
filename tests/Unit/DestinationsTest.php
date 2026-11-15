<?php

use Elcreator\aMount\Drivers\Driver;
use Elcreator\aMount\Drivers\Drivers;
use Elcreator\aMount\Drivers\S3Driver;
use Elcreator\aMount\Drivers\SftpDriver;
use Elcreator\aMount\Drivers\WebDavDriver;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Services\Actor;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Mounts;
use Elcreator\aMount\Support\Destination;
use Illuminate\Database\Capsule\Manager as Capsule;

beforeEach(fn () => amountReset());

function amountRefusal(callable $fn): ?AmountException
{
    try {
        $fn();
    } catch (AmountException $e) {
        return $e;
    }

    return null;
}

function amountLocalRoot(): string
{
    static $n = 0;
    $root = AMOUNT_TEST_REMOTE . '/host' . ++$n;
    amountEnsureDir($root);

    return $root;
}

function amountUsable(Driver $driver): bool
{
    return $driver->available();
}

// ---------------------------------------------------------------------
// Destination
// ---------------------------------------------------------------------

it('reads the host of URLs, host:port pairs and IPv6 literals', function () {
    expect(Destination::host('https://Cloud.Example/remote.php/dav/'))->toBe('cloud.example')
        ->and(Destination::host('sftp.example:2222'))->toBe('sftp.example')
        ->and(Destination::host('http://[::1]:8080/'))->toBe('::1')
        ->and(Destination::host(''))->toBeNull()
        ->and(Destination::host('http://a b/'))->toBeNull()
        ->and(Destination::host('http:///nohost'))->toBeNull();
});

it('treats only globally routable addresses as public', function (string $ip, bool $public) {
    expect(Destination::isPublic($ip))->toBe($public);
})->with([
    'loopback' => ['127.0.0.1', false],
    'private 10/8' => ['10.1.2.3', false],
    'private 192.168' => ['192.168.0.10', false],
    'metadata' => ['169.254.169.254', false],
    'cgnat' => ['100.64.0.1', false],
    'unspecified' => ['0.0.0.0', false],
    'ipv6 loopback' => ['::1', false],
    'ipv6 unique local' => ['fd00::1', false],
    'ipv4-mapped loopback' => ['::ffff:127.0.0.1', false],
    'public v4' => ['93.184.215.14', true],
    'public v6' => ['2606:4700:4700::1111', true],
]);

it('refuses a host when any of its addresses is internal', function () {
    expect(Destination::inspect('https://cloud.example/dav/'))->toBe(['status' => 'ok', 'host' => 'cloud.example', 'address' => '93.184.215.14'])
        ->and(Destination::inspect('http://mixed.test/')['status'])->toBe('private')
        ->and(Destination::inspect('http://nas.lan/')['status'])->toBe('private')
        ->and(Destination::inspect('http://localhost/')['status'])->toBe('private')
        ->and(Destination::inspect('http://169.254.169.254/latest/')['status'])->toBe('private')
        ->and(Destination::inspect('http://gone.invalid/')['status'])->toBe('unresolved')
        ->and(Destination::inspect('not a url')['status'])->toBe('invalid');
});

it('has a message for every refusal', function () {
    $lang = require dirname(__DIR__, 2) . '/lang/en/global.php';

    foreach ([Destination::INVALID, Destination::UNRESOLVED, Destination::PRIVATE] as $status) {
        expect($lang)->toHaveKey('error_destination_' . $status);
    }

    expect($lang)->toHaveKeys(['error_driver_admin_only', 'error_sftp_fingerprint', 'error_webdav_uri']);
});

// ---------------------------------------------------------------------
// Drivers on this machine's disk (H-003)
// ---------------------------------------------------------------------

it('marks exactly the folder and ZIP drivers as reaching this server', function () {
    $touching = array_values(array_map(fn (Driver $d) => $d->id(), array_filter(Drivers::all(), fn (Driver $d) => $d->touchesHost())));
    sort($touching);

    expect($touching)->toBe(['local', 'zip'])
        ->and(Drivers::get('local')->describe()['admin_only'])->toBeTrue()
        ->and(Drivers::get('s3')->describe()['admin_only'])->toBeFalse();
});

it('lets only an administrator create, test or reconfigure a local mount', function () {
    amountAdmin(1);
    amountUser(2, ['amount', 'amount_manage', 'file_manager']);
    $root = amountLocalRoot();

    $personal = amountRefusal(fn () => (new Mounts(Actor::for(2)))->create(['name' => 'Disk', 'driver' => 'local', 'config' => ['root' => '/']]));
    $site = amountRefusal(fn () => (new Mounts(Actor::for(2)))->create(['name' => 'Disk', 'driver' => 'local', 'scope' => 'site', 'config' => ['root' => '/']]));
    $draft = amountRefusal(fn () => (new Mounts(Actor::for(2)))->testDraft(['driver' => 'local', 'config' => ['root' => '/']]));
    $zip = amountRefusal(fn () => (new Mounts(Actor::for(2)))->create(['name' => 'Zip', 'driver' => 'zip', 'config' => ['path' => '/tmp/x.zip']]));

    expect($personal?->status)->toBe(403)->and($personal?->error)->toBe('admin_only')
        ->and($site?->status)->toBe(403)
        ->and($draft?->status)->toBe(403)
        ->and(Mount::query()->count())->toBe(0);

    if (amountUsable(Drivers::get('zip'))) {
        expect($zip?->status)->toBe(403);
    }

    $mount = (new Mounts(Actor::for(1)))->create(['name' => 'Disk', 'driver' => 'local', 'scope' => 'site', 'config' => ['root' => $root]]);

    // A manager may still use the administrator's mount and edit what does not move it ...
    expect((new Mounts(Actor::for(2)))->test($mount)['ok'])->toBeTrue();
    (new Mounts(Actor::for(2)))->update($mount, ['name' => 'Shared disk', 'options' => ['root' => 'backups']]);
    expect($mount->fresh()->trusted())->toBeTrue();

    // ... but not repoint it.
    $repoint = amountRefusal(fn () => (new Mounts(Actor::for(2)))->update($mount->fresh(), ['config' => ['root' => '/']]));
    expect($repoint?->status)->toBe(403)
        ->and($mount->fresh()->credentials()['root'])->toBe($root);
});

it('refuses at run time a local mount a manager set up before the fix', function () {
    amountUser(2);
    $legacy = new Mount(['uuid' => Mount::newUuid(), 'name' => 'Old', 'driver' => 'local', 'owner_id' => 2, 'options_json' => [], 'status' => Mount::STATUS_OK]);
    $legacy->setCredentials(['root' => '/']);
    $legacy->save();

    expect($legacy->trusted())->toBeFalse()
        ->and(fn () => $legacy->filesystem())->toThrow(RuntimeException::class, 'administrator');

    $probe = (new Mounts(Actor::for(2)))->test($legacy);
    expect($probe['ok'])->toBeFalse()->and($legacy->fresh()->status)->toBe(Mount::STATUS_ERROR);
});

it('stops a local mount once the administrator who set it up is demoted', function () {
    amountAdmin(1);
    $mount = (new Mounts(Actor::for(1)))->create(['name' => 'Disk', 'driver' => 'local', 'config' => ['root' => amountLocalRoot()]]);

    expect($mount->filesystem())->toBeInstanceOf(League\Flysystem\Filesystem::class);

    Capsule::table('user_attributes')->where('internalKey', 1)->update(['role' => 7]);

    expect(fn () => $mount->fresh()->filesystem())->toThrow(RuntimeException::class);
});

it('trusts mounts defined in the site configuration', function () {
    $mount = new Mount(['uuid' => Mount::newUuid(), 'name' => 'Cfg', 'driver' => 'local', 'owner_id' => null, 'options_json' => ['source' => 'config', 'config_key' => 'x']]);

    expect($mount->trusted())->toBeTrue();
});

it('never shows who configured a mount in its settings', function () {
    amountAdmin(1);
    $mounts = new Mounts(Actor::for(1));
    $mount = $mounts->create(['name' => 'Disk', 'driver' => 'local', 'config' => ['root' => amountLocalRoot()]]);

    expect($mount->option('configured_by'))->toBe(1)
        ->and($mounts->present($mount)['options'])->not->toHaveKey('configured_by');
});

// ---------------------------------------------------------------------
// Network destinations (H-002)
// ---------------------------------------------------------------------

it('refuses a WebDAV URL on an internal network for a manager', function (string $uri, string $status) {
    if (!amountUsable(Drivers::get('webdav'))) {
        $this->markTestSkipped('webdav package not installed');
    }

    amountUser(2);
    $mounts = new Mounts(Actor::for(2));

    $create = amountRefusal(fn () => $mounts->create(['name' => 'Dav', 'driver' => 'webdav', 'config' => ['base_uri' => $uri]]));
    $draft = amountRefusal(fn () => $mounts->testDraft(['driver' => 'webdav', 'config' => ['base_uri' => $uri]]));

    expect($create?->status)->toBe(422)
        ->and($create?->errors)->toHaveKey('base_uri')
        ->and($create?->errors['base_uri'])->toBe(AmountException::text('aMount::global.error_destination_' . $status, ['host' => Destination::host($uri)]))
        ->and($draft?->status)->toBe(422)
        ->and(Mount::query()->count())->toBe(0);
})->with([
    'loopback' => ['http://127.0.0.1:8080/', 'private'],
    'metadata' => ['http://169.254.169.254/', 'private'],
    'localhost' => ['http://localhost/dav/', 'private'],
    'lan name' => ['https://nas.lan/remote.php/dav/', 'private'],
    'one private record' => ['https://mixed.test/dav/', 'private'],
    'ipv6 loopback' => ['http://[::1]/', 'private'],
    'unresolvable' => ['https://gone.invalid/', 'unresolved'],
]);

it('accepts a public WebDAV URL for a manager and an internal one for an administrator', function () {
    if (!amountUsable(Drivers::get('webdav'))) {
        $this->markTestSkipped('webdav package not installed');
    }

    amountAdmin(1);
    amountUser(2);

    $public = (new Mounts(Actor::for(2)))->create(['name' => 'Cloud', 'driver' => 'webdav', 'config' => ['base_uri' => 'https://cloud.example/dav/']]);
    $lan = (new Mounts(Actor::for(1)))->create(['name' => 'NAS', 'driver' => 'webdav', 'config' => ['base_uri' => 'https://nas.lan/dav/']]);
    $scheme = amountRefusal(fn () => (new Mounts(Actor::for(1)))->create(['name' => 'X', 'driver' => 'webdav', 'config' => ['base_uri' => 'file:///etc/']]));

    expect($public->exists)->toBeTrue()
        ->and($public->trusted())->toBeFalse()
        ->and($lan->trusted())->toBeTrue()
        ->and($scheme?->errors)->toHaveKey('base_uri');
});

it('checks the address again on every connection', function () {
    if (!amountUsable(Drivers::get('webdav'))) {
        $this->markTestSkipped('webdav package not installed');
    }

    amountUser(2);
    $mount = (new Mounts(Actor::for(2)))->create(['name' => 'Cloud', 'driver' => 'webdav', 'config' => ['base_uri' => 'https://cloud.example/dav/']]);

    expect($mount->filesystem())->toBeInstanceOf(League\Flysystem\Filesystem::class);

    // The name now points inside (DNS rebinding): the next connection refuses.
    Destination::$resolver = fn (string $host) => $host === 'cloud.example' ? ['169.254.169.254'] : ['93.184.215.14'];

    expect(fn () => $mount->fresh()->filesystem())->toThrow(RuntimeException::class, 'cloud.example');
});

it('pins the checked address and follows no redirect for an untrusted WebDAV mount', function () {
    if (!class_exists(Sabre\DAV\Client::class)) {
        $this->markTestSkipped('sabre/dav not installed');
    }

    $pinned = WebDavDriver::client(['base_uri' => 'https://cloud.example/dav'], ['cloud.example' => '93.184.215.14']);
    $trusted = WebDavDriver::client(['base_uri' => 'https://nas.lan/dav'], null);
    $v6 = WebDavDriver::client(['base_uri' => 'http://cloud.example:8080/dav'], ['cloud.example' => '2606:4700:4700::1111']);

    $settings = fn ($client) => (new ReflectionProperty($client, 'curlSettings'))->getValue($client);
    $redirects = fn ($client) => (new ReflectionProperty($client, 'maxRedirects'))->getValue($client);

    expect($settings($pinned)[CURLOPT_RESOLVE])->toBe(['cloud.example:443:93.184.215.14'])
        ->and($redirects($pinned))->toBe(0)
        ->and($settings($trusted))->not->toHaveKey(CURLOPT_RESOLVE)
        ->and($redirects($trusted))->toBeGreaterThan(0)
        ->and($settings($v6)[CURLOPT_RESOLVE])->toBe(['cloud.example:8080:[2606:4700:4700::1111]'])
        ->and(fn () => WebDavDriver::client(['base_uri' => 'https://other.example/'], ['cloud.example' => '93.184.215.14']))->toThrow(RuntimeException::class);
});

it('applies the same guard to S3 endpoints, FTP and SFTP hosts and the computer gateway', function (string $driver, array $config, string $field) {
    if (!amountUsable(Drivers::get($driver))) {
        $this->markTestSkipped($driver . ' package not installed');
    }

    amountUser(2);
    $refused = amountRefusal(fn () => (new Mounts(Actor::for(2)))->testDraft(['driver' => $driver, 'config' => $config]));

    expect($refused?->status)->toBe(422)->and($refused?->errors)->toHaveKey($field);
})->with([
    's3 endpoint' => ['s3', ['bucket' => 'b', 'endpoint' => 'http://minio.lan:9000', 'path_style' => true], 'endpoint'],
    'async s3 endpoint' => ['async-s3', ['bucket' => 'b', 'endpoint' => 'http://127.0.0.1:9000'], 'endpoint'],
    'ftp host' => ['ftp', ['host' => '10.0.0.8', 'username' => 'u', 'password' => 'p', 'root' => '/'], 'host'],
    'sftp host' => ['sftp', ['host' => 'localhost', 'username' => 'u', 'password' => 'p', 'root' => '/', 'host_fingerprint' => 'SHA256:abc'], 'host'],
    'computer gateway' => ['ai-computer', ['computer' => 'laptop', 'api_key' => 'k', 'gateway' => 'http://169.254.169.254/api'], 'gateway'],
]);

it('checks and pins the virtual-hosted bucket name of a custom S3 endpoint', function () {
    $s3 = Drivers::get('s3');

    expect($s3->destinations(['endpoint' => 'https://s3.example', 'bucket' => 'media']))->toBe(['endpoint' => 'https://s3.example', 'bucket' => 'media.s3.example'])
        ->and($s3->destinations(['endpoint' => 'https://s3.example', 'bucket' => 'media', 'path_style' => true]))->toBe(['endpoint' => 'https://s3.example'])
        ->and($s3->destinations(['endpoint' => 'http://203.0.114.9:9000', 'bucket' => 'media']))->toBe(['endpoint' => 'http://203.0.114.9:9000'])
        ->and($s3->destinations(['bucket' => 'media']))->toBe(['endpoint' => '']);

    // A wildcard record sends the bucket host somewhere else than the endpoint.
    Destination::$resolver = fn (string $host) => $host === 'media.s3.example' ? ['169.254.169.254'] : ['93.184.215.14'];
    expect(fn () => $s3->pinDestinations(['endpoint' => 'https://s3.example', 'bucket' => 'media']))->toThrow(RuntimeException::class, 'media.s3.example');

    amountFakeResolver();
    $pinned = $s3->pinDestinations(['endpoint' => 'https://s3.example', 'bucket' => 'media']);

    expect(S3Driver::pinnedCurl('https://s3.example', $pinned))->toBe([CURLOPT_RESOLVE => ['s3.example:443:93.184.215.14', 'media.s3.example:443:93.184.215.14']])
        ->and(S3Driver::pinnedCurl('http://s3.example:9000', ['s3.example' => '93.184.215.14']))->toBe([CURLOPT_RESOLVE => ['s3.example:9000:93.184.215.14']])
        ->and(S3Driver::pinnedCurl('https://s3.example', null))->toBe([]);
});

// ---------------------------------------------------------------------
// SFTP host key (M-004)
// ---------------------------------------------------------------------

it('requires a pinned SFTP host key', function () {
    $sftp = Drivers::get('sftp');
    $base = ['host' => 'sftp.example', 'username' => 'u', 'password' => 'p', 'root' => '/'];

    expect($sftp->validate($base))->toHaveKey('host_fingerprint')
        ->and($sftp->validate($base + ['host_fingerprint' => 'trust me']))->toBe(['host_fingerprint' => 'aMount::global.error_sftp_fingerprint'])
        ->and($sftp->validate($base + ['host_fingerprint' => 'SHA256:nThbg6kXUpJWGl7E1IGOCspRomTxdCARLviKw6E5SY8']))->toBe([]);

    if (amountUsable($sftp)) {
        expect(fn () => $sftp->filesystem($base, ['_trusted' => true]))->toThrow(RuntimeException::class, 'host_fingerprint');

        amountUser(2);
        $refused = amountRefusal(fn () => (new Mounts(Actor::for(2)))->create(['name' => 'Box', 'driver' => 'sftp', 'config' => $base]));
        expect($refused?->errors)->toHaveKey('host_fingerprint');
    }
});

it('reads pinned host keys in the forms ssh-keygen and Flysystem use, several for rotation', function () {
    $line = '256 SHA256:nThbg6kXUpJWGl7E1IGOCspRomTxdCARLviKw6E5SY8 sftp.example (ED25519)';
    $md5 = '16:27:ac:a5:76:28:2d:36:63:1b:56:4d:eb:df:a6:48';

    expect(SftpDriver::fingerprints($line))->toBe(['SHA256:nThbg6kXUpJWGl7E1IGOCspRomTxdCARLviKw6E5SY8'])
        ->and(SftpDriver::fingerprints("SHA256:old+key/1=\n" . $md5 . ', md5:' . str_replace(':', '', $md5)))->toBe(['SHA256:old+key/1=', $md5, 'md5:' . str_replace(':', '', $md5)])
        ->and(SftpDriver::fingerprints('none here 256'))->toBe([]);
});
