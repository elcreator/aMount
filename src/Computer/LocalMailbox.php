<?php

namespace Elcreator\aMount\Computer;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This site as the server for its own session: a peer that can be one is one
 * (docs/features/29-computer-files.md, "Direct paths"). When the computer can reach this site
 * (public, same LAN, Docker on the same machine) it connects to `<site>/amount-mesh/v1/<token>`
 * and the bytes skip the gateway. The site names the address inside the sealed request, so
 * only the computer learns it, and nobody can redirect the computer elsewhere.
 *
 * A mailbox exists only while this site's own transfer holds it (made by {@see create()},
 * gone after it or after 15 idle minutes): the endpoint answers 404 to any other token, so it
 * is no storage for anybody else. It holds a few sealed chunks at a time (a window of 4).
 *
 * The worker (this class as a {@see Channel}) and the endpoint (handle()) are different PHP
 * processes; they meet in `storage/amount-mesh/<token>/`, with a locked `state.json`.
 */
final class LocalMailbox implements Channel
{
    public const PREFIX = 'amount-mesh/v1/';
    private const WINDOW = 4;
    private const CHUNK_MAX = Seal::CHUNK + 1024;
    private const IDLE = 900;

    private function __construct(private readonly string $token)
    {
    }

    public static function root(): string
    {
        $base = defined('EVO_STORAGE_PATH') ? rtrim((string) EVO_STORAGE_PATH, '/\\') : sys_get_temp_dir();

        return $base . '/amount-mesh';
    }

    /** A new mailbox for one transfer. Old ones are swept on the way. */
    public static function create(): self
    {
        self::sweep();
        $token = bin2hex(random_bytes(16));
        $dir = self::root() . '/' . $token;
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ComputerException('mailbox_unavailable', 'This site cannot keep a mailbox (storage is not writable).');
        }
        file_put_contents($dir . '/state.json', json_encode(self::emptyState()));

        return new self($token);
    }

    public function token(): string
    {
        return $this->token;
    }

    private static function emptyState(): array
    {
        return ['hello' => false, 'responses' => 0, 'done' => false, 'touched' => time(),
            'up' => ['count' => 0, 'acked' => 0, 'last' => false], 'down' => ['count' => 0, 'acked' => 0, 'last' => false]];
    }

    private static function dir(string $token): string
    {
        return self::root() . '/' . $token;
    }

    /** Read, change and write the state under a lock; null when the mailbox does not exist. */
    private static function update(string $token, ?callable $change = null): ?array
    {
        $file = self::dir($token) . '/state.json';
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file($file)) {
            return null;
        }
        $h = fopen($file, 'c+');
        if ($h === false) {
            return null;
        }
        flock($h, LOCK_EX);
        $state = json_decode((string) stream_get_contents($h), true) ?: self::emptyState();
        if ($change !== null) {
            $state = $change($state);
            $state['touched'] = time();
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, (string) json_encode($state));
        }
        flock($h, LOCK_UN);
        fclose($h);

        return $state;
    }

    public static function sweep(): void
    {
        foreach (glob(self::root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $state = @json_decode((string) @file_get_contents($dir . '/state.json'), true);
            if (!is_array($state) || (int) ($state['touched'] ?? 0) < time() - self::IDLE) {
                self::remove($dir);
            }
        }
    }

    private static function remove(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------ the site's side

    public function hasHello(): bool
    {
        return (bool) (self::update($this->token)['hello'] ?? false);
    }

    public function response(int $from, int $wait): ?string
    {
        $deadline = microtime(true) + $wait;
        do {
            $state = self::update($this->token);
            if ($state === null) {
                throw new ComputerException('session_closed', 'The mailbox is gone.');
            }
            for ($i = (int) $state['responses'] - 1; $i >= 0; $i--) {
                $sealed = (string) @file_get_contents(self::dir($this->token) . '/resp-' . $i);
                if (Seal::counterOf($sealed) >= $from) {
                    return $sealed;
                }
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(200000);
        } while (true);
    }

    public function putUp(int $n, string $sealed, bool $last): bool
    {
        $ok = false;
        self::update($this->token, function (array $s) use ($n, $sealed, $last, &$ok) {
            if ($n < $s['up']['count']) {
                $ok = true;
            } elseif ($n === $s['up']['count'] && !$s['up']['last'] && $n - $s['up']['acked'] < self::WINDOW) {
                file_put_contents(self::dir($this->token) . '/up-' . $n, $sealed);
                $s['up']['count'] = $n + 1;
                $s['up']['last'] = $last;
                $ok = true;
            }

            return $s;
        });

        return $ok;
    }

    public function takeDown(int $n, int $wait): ?array
    {
        $deadline = microtime(true) + $wait;
        do {
            $got = null;
            self::update($this->token, function (array $s) use ($n, &$got) {
                for ($i = $s['down']['acked']; $i < min($n, $s['down']['count']); $i++) {
                    @unlink(self::dir($this->token) . '/down-' . $i);
                }
                $s['down']['acked'] = max($s['down']['acked'], min($n, $s['down']['count']));
                if ($n < $s['down']['count']) {
                    $got = [(string) @file_get_contents(self::dir($this->token) . '/down-' . $n), $s['down']['last'] && $n === $s['down']['count'] - 1];
                }

                return $s;
            });
            if ($got !== null) {
                return $got;
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(150000);
        } while (true);
    }

    public function close(): void
    {
        self::remove(self::dir($this->token));
    }

    public function name(): string
    {
        return 'site mailbox';
    }

    // --------------------------------------------------------- the computer's side (HTTP)

    /**
     * The public endpoint (`OnPageNotFound`, path `amount-mesh/v1/<token>/<action>`). Null when
     * the path is not one of ours; 404 for a token that is not a live mailbox of this site.
     */
    public static function handle(string $sitePath, Request $request): ?Response
    {
        if (!str_starts_with($sitePath, self::PREFIX)) {
            return null;
        }
        if (!preg_match('#^amount-mesh/v1/([a-f0-9]{32})/(hello|reply|up/(\d+)|down/(\d+))$#', $sitePath, $m)) {
            return new Response('', 404);
        }
        $token = $m[1];
        $method = $request->getMethod();
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (self::update($token) === null) {
            return new Response('', 404, $headers);
        }
        if ($m[2] === 'hello' && $method === 'POST') {
            self::update($token, static function (array $s) { $s['hello'] = true; return $s; });

            return new Response('', 200, $headers);
        }
        if ($m[2] === 'reply' && $method === 'POST') {
            $body = (string) $request->getContent();
            if ($body === '' || strlen($body) > 9 * 1024 * 1024) {
                return new Response('', 413, $headers);
            }
            self::update($token, static function (array $s) use ($token, $body, $request) {
                file_put_contents(self::dir($token) . '/resp-' . $s['responses'], $body);
                $s['responses']++;
                $s['done'] = $s['done'] || $request->query->get('done') === '1';

                return $s;
            });

            return new Response('', 200, $headers);
        }
        if (str_starts_with($m[2], 'down/') && $method === 'PUT') {
            $n = (int) $m[4];
            $body = (string) $request->getContent();
            if ($body === '' || strlen($body) > self::CHUNK_MAX) {
                return new Response('', 413, $headers);
            }
            $status = 202;
            self::update($token, static function (array $s) use ($token, $n, $body, $request, &$status) {
                if ($n < $s['down']['count']) {
                    return $s;
                }
                if ($n > $s['down']['count'] || $s['down']['last']) {
                    $status = 409;
                } elseif ($n - $s['down']['acked'] >= self::WINDOW) {
                    $status = 429;
                } else {
                    file_put_contents(self::dir($token) . '/down-' . $n, $body);
                    $s['down']['count'] = $n + 1;
                    $s['down']['last'] = $request->query->get('last') === '1';
                }

                return $s;
            });

            return new Response('', $status, $headers);
        }
        if (str_starts_with($m[2], 'up/') && $method === 'GET') {
            $n = (int) $m[3];
            $wait = min(15, max(0, (int) $request->query->get('wait', 0)));
            $deadline = microtime(true) + $wait;
            do {
                $got = null;
                self::update($token, static function (array $s) use ($token, $n, &$got) {
                    for ($i = $s['up']['acked']; $i < min($n, $s['up']['count']); $i++) {
                        @unlink(self::dir($token) . '/up-' . $i);
                    }
                    $s['up']['acked'] = max($s['up']['acked'], min($n, $s['up']['count']));
                    if ($n < $s['up']['count']) {
                        $got = [(string) @file_get_contents(self::dir($token) . '/up-' . $n), $s['up']['last'] && $n === $s['up']['count'] - 1];
                    }

                    return $s;
                });
                if ($got !== null) {
                    return new Response($got[0], 200, $headers + ['Content-Type' => 'application/octet-stream']
                        + ($got[1] ? ['X-Mesh-Last' => '1'] : []));
                }
                if (microtime(true) >= $deadline) {
                    return new Response('', 204, $headers);
                }
                usleep(200000);
            } while (true);
        }

        return new Response('', 405, $headers);
    }
}
