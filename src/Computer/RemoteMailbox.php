<?php

namespace Elcreator\aMount\Computer;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\RequestOptions;

/**
 * A mailbox on the owner's own relay (`mesh-daemon --relay`): what a TURN server is to a
 * video call, for when neither this site nor the computer can reach the other and the bytes
 * should not pass through ai.artur.work. Same protocol as {@see LocalMailbox}, over HTTP; the
 * relay holds sealed chunks it cannot read.
 */
final class RemoteMailbox implements Channel
{
    private HttpClient $http;

    private string $base;

    public function __construct(string $relayUrl, private readonly string $token, ?HttpClient $http = null)
    {
        $this->base = rtrim($relayUrl, '/') . '/' . $token;
        $this->http = $http ?? new HttpClient(['http_errors' => false, 'timeout' => 30, 'connect_timeout' => 5, 'allow_redirects' => false]);
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @return array<string, mixed> */
    public function state(int $wait = 0): array
    {
        try {
            $r = $this->http->get($this->base . '/state', [RequestOptions::QUERY => ['wait' => $wait]]);
            $data = json_decode((string) $r->getBody(), true);

            return $r->getStatusCode() === 200 && is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function hasHello(): bool
    {
        return (bool) ($this->state()['hello'] ?? false);
    }

    public function response(int $from, int $wait): ?string
    {
        $deadline = microtime(true) + $wait;
        do {
            $count = (int) ($this->state()['responses'] ?? 0);
            for ($i = $count - 1; $i >= 0; $i--) {
                $r = $this->http->get($this->base . '/response/' . $i);
                $sealed = (string) $r->getBody();
                if ($r->getStatusCode() === 200 && Seal::counterOf($sealed) >= $from) {
                    return $sealed;
                }
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(300000);
        } while (true);
    }

    public function putUp(int $n, string $sealed, bool $last): bool
    {
        $r = $this->http->put($this->base . '/up/' . $n, [RequestOptions::QUERY => ['last' => $last ? 1 : 0], RequestOptions::BODY => $sealed]);
        if ($r->getStatusCode() === 429) {
            return false;
        }
        if ($r->getStatusCode() >= 300) {
            throw new ComputerException('relay_failed', 'The relay refused a chunk (' . $r->getStatusCode() . ').');
        }

        return true;
    }

    public function takeDown(int $n, int $wait): ?array
    {
        $r = $this->http->get($this->base . '/down/' . $n, [RequestOptions::QUERY => ['wait' => min(15, $wait)], RequestOptions::TIMEOUT => 25]);
        if ($r->getStatusCode() === 204) {
            return null;
        }
        if ($r->getStatusCode() !== 200) {
            throw new ComputerException('relay_failed', 'The relay lost a chunk (' . $r->getStatusCode() . ').');
        }

        return [(string) $r->getBody(), $r->getHeaderLine('X-Mesh-Last') === '1'];
    }

    public function close(): void
    {
        try {
            $this->http->post($this->base . '/close');
        } catch (\Throwable) {
        }
    }

    public function name(): string
    {
        return 'own relay';
    }
}
