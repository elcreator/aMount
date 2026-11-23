<?php

namespace Elcreator\aMount\Computer;

use Elcreator\aMount\Support\Config;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * The LendSpare (lendspare.com) side of a computer's shared folders: the account's computers, and
 * the sealed mailbox it relays sessions through. Computers are connected to LendSpare with its
 * desktop application; the shared folders moved there from ai.artur.work.
 * Everything sent through it is already sealed: this class moves opaque bytes.
 * One instance carries one API key. The key needs "Shared folders on your computers" switched
 * on for it on the LendSpare profile (read, or read and write).
 */
class Gateway
{
    public const DEFAULT_URL = 'https://lendspare.com/api/v1';
    /** Where the shared folders were before LendSpare: a saved mount naming it means the default. */
    public const LEGACY_URL = 'https://ai.artur.work/api/v1';

    private HttpClient $http;

    /** The API address: `computers.url` (env AMOUNT_LENDSPARE_URL), else lendspare.com. */
    public static function defaultUrl(): string
    {
        $configured = trim((string) (Config::get('computers.url') ?? getenv('AMOUNT_LENDSPARE_URL') ?: ''));

        return $configured !== '' ? $configured : self::DEFAULT_URL;
    }

    public function __construct(private readonly string $apiKey, private readonly string $baseUrl = self::DEFAULT_URL, ?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient(['http_errors' => false, 'timeout' => 40, 'connect_timeout' => 10]);
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /** @return array<int, array<string, mixed>> */
    public function computers(): array
    {
        return (array) ($this->json('GET', '/computers')['computers'] ?? []);
    }

    /** @return array<string, mixed> the session view (state, response, chunk counters, rev) */
    public function open(int $computerId, string $mode, string $envelope, int $wait = 10): array
    {
        return $this->json('POST', '/computers/' . $computerId . '/sessions', [RequestOptions::JSON => [
            'mode' => $mode, 'request' => base64_encode($envelope), 'wait' => $wait,
        ]]);
    }

    /** @return array<string, mixed> */
    public function state(string $session, string $since = '', int $wait = 0): array
    {
        return $this->json('GET', '/computers/sessions/' . $session, [RequestOptions::QUERY => ['since' => $since, 'wait' => $wait]]);
    }

    /** @return bool false while the computer is a window behind (send it again shortly) */
    public function putUp(string $session, int $n, string $bytes, bool $last): bool
    {
        $response = $this->send('PUT', '/computers/sessions/' . $session . '/up/' . $n, [
            RequestOptions::QUERY => ['last' => $last ? 1 : 0],
            RequestOptions::BODY => $bytes,
            RequestOptions::HEADERS => ['Content-Type' => 'application/octet-stream'],
        ]);
        if ($response->getStatusCode() === 429) {
            return false;
        }
        $this->check($response);

        return true;
    }

    /** @return array{0: string, 1: bool}|null chunk n and whether it is the last, or null when not there yet */
    public function takeDown(string $session, int $n, int $wait = 10): ?array
    {
        $response = $this->send('GET', '/computers/sessions/' . $session . '/down/' . $n, [RequestOptions::QUERY => ['wait' => $wait]]);
        if ($response->getStatusCode() === 204) {
            return null;
        }
        $this->check($response);

        return [(string) $response->getBody(), $response->getHeaderLine('X-Mesh-Last') === '1'];
    }

    public function close(string $session): void
    {
        try {
            $this->send('POST', '/computers/sessions/' . $session . '/close', [RequestOptions::JSON => new \stdClass()]);
        } catch (\Throwable) {
            // The gateway closes idle sessions itself within ten minutes.
        }
    }

    /** @return array{ticket: string, expiresAt: int, addresses: string[]} */
    public function ticket(int $computerId, string $mode): array
    {
        return $this->json('POST', '/computers/' . $computerId . '/ticket', [RequestOptions::JSON => ['mode' => $mode]]);
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $path, array $options = []): array
    {
        $response = $this->send($method, $path, $options);
        $this->check($response);
        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : [];
    }

    private function send(string $method, string $path, array $options): ResponseInterface
    {
        $options[RequestOptions::HEADERS] = ($options[RequestOptions::HEADERS] ?? []) + [
            'X-Api-Key' => $this->apiKey,
            'Accept' => 'application/json',
        ];

        try {
            return $this->http->request($method, $this->baseUrl() . $path, $options);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new ComputerException('gateway_unreachable', 'LendSpare could not be reached: ' . $e->getMessage());
        }
    }

    private function check(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 300) {
            return;
        }
        $data = json_decode((string) $response->getBody(), true);
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        throw new ComputerException((string) ($error['code'] ?? ('http_' . $status)),
            (string) ($error['message'] ?? ('LendSpare answered ' . $status . '.')), $status);
    }
}
