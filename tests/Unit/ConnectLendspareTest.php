<?php

use Elcreator\aMount\Computer\ComputerException;
use Elcreator\aMount\Computer\Connect;
use Elcreator\aMount\Computer\ConnectedKey;
use Elcreator\aMount\Computer\Gateway;
use Elcreator\aMount\Computer\KeySource;
use Elcreator\aMount\Drivers\ComputerDriver;
use Elcreator\aMount\Drivers\Driver;
use Elcreator\aMount\Drivers\Drivers;
use Elcreator\aMount\Http\Controllers\BootstrapController;
use Elcreator\aMount\Http\Controllers\ComputerController;
use Elcreator\aMount\Listeners\ConnectReturn;
use Elcreator\aMount\Models\Mount;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;

/**
 * "Connect LendSpare": the manager is sent to LendSpare's consent page and back, and the key it
 * brings lists their computers in the mount form - nobody types a computer's name or copies a key.
 */

const CL_CALLBACK = '/manager/modules/abc123/computers/connect/callback';

function clFakeLendspare(array $responses, array &$sent = []): void
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($sent));
    Connect::$http = new HttpClient(['handler' => $stack, 'http_errors' => false]);
}

function clRequest(string $path, array $query = []): Request
{
    return Request::create('https://cms.example.com' . $path, 'GET', $query);
}

beforeEach(function () {
    amountReset();
    $_SESSION = [];
    putenv('AMOUNT_GATEWAY_KEY');
});

afterEach(function () {
    Connect::$http = null;
    ComputerDriver::$gatewayFactory = null;
    $_SESSION = [];
});

it('makes the S256 challenge of RFC 7636 and a fresh verifier and state each time', function () {
    expect(Connect::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
    $a = Connect::pkce();
    $b = Connect::pkce();
    expect($a['challenge'])->toBe(Connect::challenge($a['verifier']))
        ->and(strlen($a['verifier']))->toBeGreaterThanOrEqual(43)
        ->and($a['state'])->not->toBe($b['state']);
});

it('sends the browser to LendSpare\'s consent page of the configured LendSpare', function () {
    $url = Connect::authorizeUrl('https://cms.example.com', 'https://cms.example.com/amount/lendspare-return?m=x', 'st', 'ch', 'http://localhost:8090/api/v1');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    expect(strtok($url, '?'))->toBe('http://localhost:8090/sites/connect')
        ->and($q)->toBe(['app' => 'amount', 'site' => 'https://cms.example.com', 'redirect_uri' => 'https://cms.example.com/amount/lendspare-return?m=x',
            'state' => 'st', 'code_challenge' => 'ch', 'code_challenge_method' => 'S256'])
        ->and(Connect::lendspareOrigin('https://lendspare.com/api/v1'))->toBe('https://lendspare.com')
        ->and(Connect::siteOrigin('https://cms.example.com:8443/sub/'))->toBe('https://cms.example.com:8443');
});

it('comes back through a relay that forwards only LendSpare\'s answer to the callback path', function () {
    $redirect = Connect::relayUri('https://cms.example.com', 'https://cms.example.com' . CL_CALLBACK);
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);
    expect(strtok($redirect, '?'))->toBe('https://cms.example.com/amount/lendspare-return')
        ->and($q['m'])->toBe(CL_CALLBACK);

    $target = Connect::relayTarget(['m' => CL_CALLBACK, 'code' => 'c1', 'state' => 's1', 'evil' => 'x']);
    expect($target)->toBe(CL_CALLBACK . '?code=c1&state=s1');

    foreach (['https://evil.example' . CL_CALLBACK, '/manager/../x/computers/connect/callback', '//evil.example/computers/connect/callback', '/manager/files', null] as $bad) {
        expect(Connect::relayTarget(['m' => $bad, 'code' => 'c']))->toBeNull();
    }
});

it('answers the relay path on the front end and nothing else', function () {
    expect(ConnectReturn::page('/some/page', []))->toBeNull()
        ->and(ConnectReturn::page('/amount/lendspare-return?m=/x', ['m' => '/x'])[0])->toBe(400);

    [$status, $html] = ConnectReturn::page('/amount/lendspare-return', ['m' => CL_CALLBACK, 'code' => 'c1', 'state' => 's1']);
    expect($status)->toBe(200)
        ->and($html)->toContain('no-referrer')
        ->and($html)->toContain(htmlspecialchars(CL_CALLBACK . '?code=c1&state=s1', ENT_QUOTES));
});

it('trades the code for a key server to server, and reports LendSpare\'s refusal without a key', function () {
    $sent = [];
    clFakeLendspare([new Response(200, [], json_encode(['access_token' => 'ls-key-1', 'token_type' => 'Bearer']))], $sent);
    expect(Connect::exchange('code-1', 'verifier-1', 'https://cms.example.com/r', 'https://lendspare.test/api/v1'))->toBe('ls-key-1');
    expect((string) $sent[0]['request']->getUri())->toBe('https://lendspare.test/sites/connect/token')
        ->and(json_decode((string) $sent[0]['request']->getBody(), true))->toBe(['grant_type' => 'authorization_code', 'code' => 'code-1',
            'code_verifier' => 'verifier-1', 'redirect_uri' => 'https://cms.example.com/r']);

    clFakeLendspare([new Response(400, [], json_encode(['error' => 'invalid_grant', 'error_description' => 'This code has expired. Connect again.']))]);
    expect(fn () => Connect::exchange('old', 'v', 'https://cms.example.com/r'))->toThrow(ComputerException::class, 'This code has expired');
});

it('keeps each manager\'s key encrypted, and a mount uses its own key, then its manager\'s, then the site\'s', function () {
    ConnectedKey::put(7, 'key-of-7');
    $stored = Illuminate\Support\Facades\DB::table('amount_state')->where('key', 'computers_key_7')->value('value');
    expect($stored)->not->toContain('key-of-7')
        ->and(ConnectedKey::get(7))->toBe('key-of-7')
        ->and(ConnectedKey::get(8))->toBeNull()
        ->and(ConnectedKey::get(null))->toBeNull();

    putenv('AMOUNT_GATEWAY_KEY=site-key');
    expect(KeySource::resolve('own', 7))->toBe('own')
        ->and(KeySource::resolve('', 7))->toBe('key-of-7')
        ->and(KeySource::resolve('', 8))->toBe('site-key');

    ConnectedKey::forget(7);
    expect(KeySource::resolve('', 7))->toBe('site-key');
});

it('gives a shared computer mount the key of the manager who set it up', function () {
    ConnectedKey::put(5, 'key-of-5');
    $keys = [];
    ComputerDriver::$gatewayFactory = static function (string $key, string $url) use (&$keys): Gateway {
        $keys[] = $key;

        return new Gateway($key, $url, new HttpClient(['handler' => HandlerStack::create(new MockHandler())]));
    };
    $mount = new Mount(['uuid' => Mount::newUuid(), 'name' => 'PC', 'driver' => 'ai-computer', 'owner_id' => null,
        'options_json' => ['configured_by' => 5], 'status' => Mount::STATUS_OK]);
    $mount->setCredentials(['computer' => '12']);
    $mount->filesystem();

    expect($keys)->toBe(['key-of-5']);
});

it('stores the key a successful round trip brings, for the manager who started it', function () {
    amountUser(3);
    AmountTestCore::$loginUserId = 3;
    $controller = new ComputerController();

    $out = $controller->connect(clRequest('/manager/modules/abc123/computers/connect'));
    parse_str((string) parse_url($out->getTargetUrl(), PHP_URL_QUERY), $q);
    expect(strtok($out->getTargetUrl(), '?'))->toBe('https://lendspare.com/sites/connect')
        ->and($q['site'])->toBe('https://cms.example.com')
        ->and($q['redirect_uri'])->toBe(Connect::relayUri('https://cms.example.com', CL_CALLBACK))
        ->and($q['state'])->toBe($_SESSION['amount_lendspare_connect']['state']);

    $sent = [];
    clFakeLendspare([new Response(200, [], json_encode(['access_token' => 'ls-key-3']))], $sent);
    $back = $controller->callback(clRequest(CL_CALLBACK, ['code' => 'c3', 'state' => $q['state']]));

    expect(ConnectedKey::get(3))->toBe('ls-key-3')
        ->and(json_decode((string) $sent[0]['request']->getBody(), true)['redirect_uri'])->toBe($q['redirect_uri'])
        ->and($back->getTargetUrl())->toContain('lendspare=connected')
        ->and($_SESSION)->not->toHaveKey('amount_lendspare_connect');
});

it('refuses a callback with another state, a stale start, or a denial, and stores nothing', function () {
    amountUser(3);
    AmountTestCore::$loginUserId = 3;
    $controller = new ComputerController();
    clFakeLendspare([new Response(200, [], json_encode(['access_token' => 'never']))]);

    $_SESSION['amount_lendspare_connect'] = ['verifier' => 'v', 'state' => 'good', 'at' => time()];
    expect($controller->callback(clRequest(CL_CALLBACK, ['code' => 'c', 'state' => 'forged']))->getTargetUrl())->toContain('lendspare=failed');

    $_SESSION['amount_lendspare_connect'] = ['verifier' => 'v', 'state' => 'good', 'at' => time() - Connect::PENDING_TTL - 1];
    expect($controller->callback(clRequest(CL_CALLBACK, ['code' => 'c', 'state' => 'good']))->getTargetUrl())->toContain('lendspare=failed');

    $_SESSION['amount_lendspare_connect'] = ['verifier' => 'v', 'state' => 'good', 'at' => time()];
    expect($controller->callback(clRequest(CL_CALLBACK, ['error' => 'access_denied', 'state' => 'good']))->getTargetUrl())->toContain('lendspare=failed');

    expect(ConnectedKey::get(3))->toBeNull();
});

it('tells the page whether LendSpare is connected, and forgets the key on disconnect', function () {
    amountUser(3);
    AmountTestCore::$loginUserId = 3;
    $boot = fn () => json_decode((string) (new BootstrapController())->show()->getContent(), true)['computers'];

    expect($boot())->toBe(['key' => null]);
    putenv('AMOUNT_GATEWAY_KEY=site-key');
    expect($boot())->toBe(['key' => 'configured']);
    ConnectedKey::put(3, 'k');
    expect($boot())->toBe(['key' => 'connected']);

    (new ComputerController())->disconnect();
    expect(ConnectedKey::get(3))->toBeNull();
});

it('offers the computers first and lets the form pick one instead of typing it', function () {
    $first = array_key_first(Drivers::all());
    $computer = collect((new ComputerDriver())->fields())->firstWhere('name', 'computer');

    expect($first)->toBe('ai-computer')
        ->and((new ComputerDriver())->group())->toBe(Driver::GROUP_COMPUTER)
        ->and($computer['picker'])->toBe('computers');
});

it('returns to the module page inside the manager frame', function () {
    expect(Connect::framedUrl('https://cms.example.com/manager/modules/abc/?lendspare=connected', 'https://cms.example.com/manager/'))
        ->toBe('https://cms.example.com/manager/#modules/abc/?lendspare=connected')
        ->and(Connect::framedUrl('https://cms.example.com/x/?a=1', ''))->toBe('https://cms.example.com/x/?a=1');
});
