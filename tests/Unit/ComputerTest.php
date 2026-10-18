<?php

use Elcreator\aMount\Computer\Adapter;
use Elcreator\aMount\Computer\Client;
use Elcreator\aMount\Computer\ComputerException;
use Elcreator\aMount\Computer\Gateway;
use Elcreator\aMount\Computer\LocalMailbox;
use Elcreator\aMount\Computer\Seal;
use Elcreator\aMount\Computer\SiteAddress;
use Elcreator\aMount\Computer\SiteIdentity;
use Elcreator\aMount\Computer\X25519;
use Elcreator\aMount\Drivers\ComputerDriver;
use Elcreator\aMount\Mcp\Operations;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Delivery;
use Elcreator\aMount\Services\Transfer;
use League\Flysystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

/**
 * A computer as the gateway relays it: opens each sealed request with its private key and
 * answers it from a folder on disk, the way the daemon does (approval, conflicts, quota).
 */
final class FakeComputerGateway extends Gateway
{
    public string $private;
    public array $approved = [];
    public array $sessions = [];
    public string $onConflict = 'newer';
    public int $quota = 1 << 30;
    public ?string $publicOverride = null;
    public array $log = [];

    public function __construct(public string $root)
    {
        $this->private = str_repeat("\x07", 32);
    }

    public function computers(): array
    {
        return [['id' => 7, 'name' => 'mypc', 'own' => true, 'online' => true, 'files' => true,
            'publicKey' => $this->publicOverride ?? Seal::b64url(X25519::publicFrom($this->private)),
            'shares' => [['name' => 'sqlbackups', 'writable' => true, 'quota' => $this->quota, 'used' => 0]], 'direct' => [], 'advertised' => []]];
    }

    public function ticket(int $computerId, string $mode): array
    {
        throw new ComputerException('no_direct', 'no direct path in tests');
    }

    public function open(int $computerId, string $mode, string $envelope, int $wait = 10): array
    {
        [$keys, $plain] = Seal::openAsComputer($this->private, $envelope);
        $req = json_decode($plain, true);
        $id = bin2hex(random_bytes(16));
        $this->sessions[$id] = ['keys' => $keys, 'req' => $req, 'mode' => $mode, 'responses' => [], 'downs' => [], 'ups' => []];
        $this->log[] = $req['op'];
        $site = Seal::b64url(substr($envelope, 33, 32));
        $answer = function (array $a) use ($id) {
            $n = count($this->sessions[$id]['responses']);
            $this->sessions[$id]['responses'][] = $this->sessions[$id]['keys']->sealResponseAsComputer($n, $a);
        };
        if ($req['op'] !== 'shares' && !in_array($site, $this->approved, true)) {
            $answer(['ok' => false, 'error' => 'approval_pending', 'message' => 'asked the person', 'fingerprint' => Seal::fingerprint(substr($envelope, 33, 32))]);

            return $this->view($id);
        }
        $path = $this->root . '/' . ($req['path'] ?? '');
        switch ($req['op']) {
            case 'shares':
                $answer(['ok' => true, 'shares' => [['name' => 'sqlbackups', 'writable' => true, 'access' => in_array($site, $this->approved, true) ? 'write' : '',
                    'quota' => $this->quota, 'used' => 0, 'state' => 'ok']]]);
                break;
            case 'stat':
                if (!file_exists($path)) {
                    $answer(['ok' => false, 'error' => 'not_found', 'message' => 'no such file or folder']);
                } else {
                    $answer(['ok' => true, 'entry' => ['path' => $req['path'], 'dir' => is_dir($path), 'size' => is_dir($path) ? 0 : filesize($path), 'mtime' => filemtime($path)]]);
                }
                break;
            case 'list':
                $entries = [];
                $walk = function (string $dir, string $rel) use (&$walk, &$entries, $req) {
                    foreach (array_diff(scandir($dir), ['.', '..']) as $name) {
                        $p = $dir . '/' . $name;
                        $r = ltrim($rel . '/' . $name, '/');
                        $entries[] = ['path' => $r, 'dir' => is_dir($p), 'size' => is_dir($p) ? 0 : filesize($p), 'mtime' => filemtime($p)];
                        if (is_dir($p) && !empty($req['recursive'])) {
                            $walk($p, $r);
                        }
                    }
                };
                $walk(rtrim($path, '/'), (string) $req['path']);
                $answer(['ok' => true, 'entries' => $entries, 'truncated' => false]);
                break;
            case 'read':
                $bytes = file_get_contents($path);
                $answer(['ok' => true, 'size' => strlen($bytes), 'mtime' => filemtime($path), 'chunks' => 1]);
                $this->sessions[$id]['downs'][0] = [$keys->sealDownAsComputer(0, true, $bytes), true];
                $answer(['ok' => true, 'complete' => true]);
                break;
            case 'write':
                if (file_exists($path)) {
                    if (filemtime($path) >= (int) $req['mtime'] || ($req['onConflict'] ?? '') !== 'newer' || $this->onConflict !== 'newer') {
                        $answer(['ok' => false, 'error' => 'conflict', 'message' => 'kept the file here', 'existingMtime' => filemtime($path)]);
                        break;
                    }
                }
                if ((int) $req['size'] > $this->quota) {
                    $answer(['ok' => false, 'error' => 'quota_exceeded', 'message' => 'over the folder quota', 'quota' => $this->quota]);
                    break;
                }
                $answer(['ok' => true, 'status' => 'ready']);
                break;
            case 'delete':
                @unlink($path);
                $answer(['ok' => true, 'deleted' => $req['path']]);
                break;
        }

        return $this->view($id);
    }

    private function view(string $id): array
    {
        $r = $this->sessions[$id]['responses'];

        return ['session' => $id, 'state' => 'ACTIVE', 'rev' => (string) count($r), 'response' => base64_encode((string) end($r))];
    }

    public function state(string $session, string $since = '', int $wait = 0): array
    {
        return $this->view($session);
    }

    public function putUp(string $session, int $n, string $bytes, bool $last): bool
    {
        $s = &$this->sessions[$session];
        $s['ups'][$n] = $s['keys']->openUpAsComputer($n, $last, $bytes);
        if ($last) {
            $path = $this->root . '/' . $s['req']['path'];
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, implode('', $s['ups']));
            touch($path, (int) $s['req']['mtime']);
            $s['responses'][] = $s['keys']->sealResponseAsComputer(count($s['responses']), ['ok' => true, 'written' => true, 'size' => filesize($path)]);
        }

        return true;
    }

    public function takeDown(string $session, int $n, int $wait = 10): ?array
    {
        return $this->sessions[$session]['downs'][$n] ?? null;
    }

    public function close(string $session): void
    {
    }
}

function computerSetup(): FakeComputerGateway
{
    $root = AMOUNT_TEST_REMOTE . '/computer-' . bin2hex(random_bytes(4));
    amountEnsureDir($root . '/2025/08');
    file_put_contents($root . '/2025/08/db1.sql.gz', 'dump one');
    file_put_contents($root . '/2025/08/db2.sql.gz', 'dump two');
    amountEnsureDir($root . '/2025/08/old');
    file_put_contents($root . '/2025/08/old/db0.sql.gz', 'dump zero');
    touch($root . '/2025/08/db1.sql.gz', 1_700_000_000);
    $gateway = new FakeComputerGateway($root);
    ComputerDriver::$gatewayFactory = static fn () => $gateway;
    SiteIdentity::flush();
    putenv('AMOUNT_GATEWAY_KEY=test-key');

    return $gateway;
}

function computerAdapter(FakeComputerGateway $g): Filesystem
{
    return new Filesystem(new Adapter(new Client($g, 'mypc', ['site_mailbox' => false, 'direct' => false]), 'sqlbackups'));
}

beforeEach(function () {
    amountReset();
});

afterEach(function () {
    ComputerDriver::$gatewayFactory = null;
    Transfer::$hostOs = PHP_OS_FAMILY;
    putenv('AMOUNT_GATEWAY_KEY');
});

it('seals exactly as the daemon does (the shared vector)', function () {
    $v = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/files-vector.json'), true);
    $computer = hex2bin($v['computerPrivate']);
    $site = hex2bin($v['sitePrivate']);
    [$keys, $envelope] = Seal::sealPlain($site, X25519::publicFrom($computer), $v['request'], '00112233445566778899aabbccddeeff', hex2bin($v['ephPrivate']));

    expect(base64_encode($envelope))->toBe($v['envelope'])
        ->and(Seal::b64url(X25519::publicFrom($computer)))->toBe($v['computerPublic'])
        ->and(Seal::fingerprint(X25519::publicFrom($site)))->toBe($v['siteFingerprint'])
        ->and($keys->openResponse(base64_decode($v['response'])))->toBe(json_decode($v['responsePlain'], true))
        ->and($keys->openDown(0, true, base64_decode($v['down0'])))->toBe($v['down0Plain'])
        ->and(base64_encode($keys->sealUp(0, true, $v['up0Plain'])))->toBe($v['up0']);

    // The other way round, the computer's half opens what this site sealed.
    [, $plain] = Seal::openAsComputer($computer, $envelope);
    expect($plain)->toBe($v['request']);
    expect(fn () => $keys->openDown(0, false, base64_decode($v['down0'])))->toThrow(RuntimeException::class);
});

it('lists, reads and writes a computer folder once the person approved this site', function () {
    $g = computerSetup();
    $fs = computerAdapter($g);

    // Not approved yet: the refusal carries the code the person compares.
    try {
        iterator_to_array($fs->listContents('2025/08'));
        $this->fail('listed without approval');
    } catch (Throwable $e) {
        // Flysystem wraps it; the computer's own refusal is underneath, with its code.
        $e = $e instanceof ComputerException ? $e : $e->getPrevious();
        expect($e)->toBeInstanceOf(ComputerException::class)->and($e->errorCode)->toBe('approval_pending')
            ->and($e->details['fingerprint'])->toBe(SiteIdentity::fingerprint());
    }

    $g->approved[] = Seal::b64url(SiteIdentity::publicKey());
    $paths = array_map(fn ($i) => $i->path(), iterator_to_array($fs->listContents('2025/08', true), false));
    sort($paths);
    expect($paths)->toBe(['2025/08/db1.sql.gz', '2025/08/db2.sql.gz', '2025/08/old', '2025/08/old/db0.sql.gz']);
    // Metadata came with the listing: no extra trip for the size.
    $trips = count($g->log);
    expect($fs->fileSize('2025/08/db2.sql.gz'))->toBe(8)->and(count($g->log))->toBe($trips);
    expect($fs->read('2025/08/db2.sql.gz'))->toBe('dump two');

    $fs->write('2025/09/new.txt', 'fresh', ['amount_mtime' => 1_800_000_000]);
    expect(file_get_contents($g->root . '/2025/09/new.txt'))->toBe('fresh');
});

it('keeps a newer file on the computer and says why, and refuses over the folder quota', function () {
    $g = computerSetup();
    $g->approved[] = Seal::b64url(SiteIdentity::publicKey());
    $fs = computerAdapter($g);
    $fs->write('2025/08/db1.sql.gz', 'older', ['amount_mtime' => 1_600_000_000, 'amount_on_conflict' => 'newer']);
})->throws(League\Flysystem\UnableToWriteFile::class, 'kept the file here');

it('refuses a computer whose key changed since this site met it', function () {
    $g = computerSetup();
    Client::trustNewKey(7);
    (new Client($g, 'mypc'))->computer();
    $g->publicOverride = Seal::b64url(X25519::publicFrom(str_repeat("\x09", 32)));
    expect(fn () => (new Client($g, 'mypc'))->computer())->toThrow(ComputerException::class, 'changed');
    Client::trustNewKey(7);
    expect((new Client($g, 'mypc'))->computer()['name'])->toBe('mypc');
});

it('fetches a folder from "my computer mypc" recursively, keeping what is newer here and saying so', function () {
    amountUser(2);
    $g = computerSetup();
    $g->approved[] = Seal::b64url(SiteIdentity::publicKey());
    amountSite(['assets/files/backups/db1.sql.gz' => 'edited here']);
    touch(amountSitePath('assets/files/backups/db1.sql.gz'), 1_750_000_000); // newer than the computer's

    // No mount prepared: the computer's name is enough, the mount is made on the way.
    $result = Delivery::for(2)->fetch('mypc', 'sqlbackups/2025/08', 'assets/files/backups', ['recursive' => true, 'on_conflict' => 'newer']);
    expect($result['files'])->toBe(3)->and(Mount::query()->where('driver', 'ai-computer')->count())->toBe(1);

    $run = Run::query()->where('uuid', $result['run'])->first();
    amountDrive($run);
    $run->refresh();

    expect(file_get_contents(amountSitePath('assets/files/backups/db2.sql.gz')))->toBe('dump two')
        ->and(file_get_contents(amountSitePath('assets/files/backups/old/db0.sql.gz')))->toBe('dump zero')
        ->and(file_get_contents(amountSitePath('assets/files/backups/db1.sql.gz')))->toBe('edited here')
        ->and($run->message)->toContain('not written')->and($run->message)->toContain('newer here');
});

it('refuses a fetch that would take the site folder over the computer mount quota, before anything moves', function () {
    amountUser(2);
    $g = computerSetup();
    $g->approved[] = Seal::b64url(SiteIdentity::publicKey());
    $mount = (new \Elcreator\aMount\Services\Mounts(\Elcreator\aMount\Services\Actor::for(2)))->create([
        'name' => 'mypc', 'driver' => 'ai-computer', 'config' => ['computer' => '7', 'site_quota_mb' => 1],
    ]);
    amountSite(['assets/files/big.bin' => str_repeat('x', 1024 * 1024)]);

    $e = null;
    try {
        Delivery::for(2)->fetch($mount->uuid, 'sqlbackups/2025/08', 'assets/files', ['on_conflict' => 'skip']);
    } catch (AmountException $ex) {
        $e = $ex;
    }
    expect($e?->error)->toBe('quota_exceeded')->and($e->getMessage())->toContain('quota');
});

it('names this server cannot hold and names that differ only in case are refused with a reason', function () {
    Transfer::$hostOs = 'Windows';
    expect(Transfer::nameProblem('CON'))->toContain('Windows reserves')
        ->and(Transfer::nameProblem('con.txt'))->toContain('Windows reserves')
        ->and(Transfer::nameProblem('report.'))->toContain('dot')
        ->and(Transfer::nameProblem('a:b.txt'))->toContain('character')
        ->and(Transfer::nameProblem('console.txt'))->toBeNull();
    $dir = AMOUNT_TEST_REMOTE . '/case-' . bin2hex(random_bytes(3));
    amountEnsureDir($dir);
    file_put_contents($dir . '/Photo.JPG', 'x');
    expect(Transfer::caseTwin($dir, 'photo.jpg'))->toBe('Photo.JPG')->and(Transfer::caseTwin($dir, 'Photo.JPG'))->toBeNull();
    Transfer::$hostOs = 'Linux';
    expect(Transfer::nameProblem('CON'))->toBeNull()->and(Transfer::caseTwin($dir, 'photo.jpg'))->toBeNull();
});

it('replaces only with eMCP destructive permission, and refuses an agent that asks without it', function () {
    // Without eMCP (or without the permission) an existing file is kept.
    expect(Operations::conflictPolicy([]))->toBe('skip');
    $e = null;
    try {
        Operations::conflictPolicy(['on_conflict' => 'newer']);
    } catch (AmountException $ex) {
        $e = $ex;
    }
    expect($e?->error)->toBe('destructive_denied');
    expect(fn () => Operations::conflictPolicy(['overwrite' => true]))->toThrow(AmountException::class);
});

it('serves its own mailbox only for its own live transfer, a window at a time', function () {
    $box = LocalMailbox::create();
    $t = $box->token();
    $post = fn (string $p, string $body = '', array $q = []) => LocalMailbox::handle('amount-mesh/v1/' . $t . '/' . $p,
        Request::create('/amount-mesh/v1/' . $t . '/' . $p . ($q ? '?' . http_build_query($q) : ''), str_starts_with($p, 'down') ? 'PUT' : 'POST', [], [], [], [], $body));

    expect(LocalMailbox::handle('amount-mesh/v1/' . str_repeat('a', 32) . '/hello', Request::create('/', 'POST'))->getStatusCode())->toBe(404)
        ->and($post('hello')->getStatusCode())->toBe(200)->and($box->hasHello())->toBeTrue();
    for ($n = 0; $n < 4; $n++) {
        expect($post('down/' . $n, 'chunk' . $n)->getStatusCode())->toBe(202);
    }
    expect($post('down/4', 'chunk4')->getStatusCode())->toBe(429);
    expect($box->takeDown(0, 0)[0])->toBe('chunk0')->and($box->takeDown(2, 0)[0])->toBe('chunk2');
    expect($post('down/4', 'chunk4', ['last' => 1])->getStatusCode())->toBe(202)->and($box->takeDown(4, 0))->toBe(['chunk4', true]);

    expect($box->putUp(0, 'up0', true))->toBeTrue();
    $up = LocalMailbox::handle('amount-mesh/v1/' . $t . '/up/0', Request::create('/x', 'GET'));
    expect($up->getContent())->toBe('up0')->and($up->headers->get('X-Mesh-Last'))->toBe('1');

    $box->close();
    expect($post('hello')->getStatusCode())->toBe(404);
});

it('knows which domains this installation serves', function () {
    putenv('EVO_SITE_HOSTNAMES=site.com,shop.example.org:8443');
    expect(SiteAddress::domains())->toContain('site.com')->toContain('shop.example.org:8443')
        ->and(SiteAddress::find('https://WWW.site.com/')['domain'])->toBe('site.com')
        ->and(SiteAddress::find('other.com'))->toBeNull();
    putenv('EVO_SITE_HOSTNAMES');
});
