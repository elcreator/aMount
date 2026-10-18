<?php

namespace Elcreator\aMount\Computer;

/**
 * Straight to the computer, when it listens and this site can reach it - the same LAN, the
 * same Docker host, or a port its owner forwarded (docs/features/29-computer-files.md). One
 * streaming HTTP/1.1 request of length-prefixed frames, both ways at once:
 *
 *     frame    = type(1) | flags(1) | length(4) | payload
 *     to it    : 'H' {ticket, mode, request}, then 'U' chunks (flags&1 = last)
 *     from it  : 'R' sealed answer, 'D' sealed chunk (flags&1 = last), 'X' refusal
 *
 * Raw sockets rather than an HTTP library: the request body has to keep flowing after the
 * answer has started (a write is uploaded only once the computer says "ready").
 */
final class DirectChannel implements Channel
{
    /** @var resource */
    private $socket;

    /** @var string[] sealed answers in arrival order */
    private array $responses = [];

    /** @var array<int, array{0: string, 1: bool}> */
    private array $downs = [];

    private int $downSeen = 0;

    private bool $bodyOpen = true;

    private bool $chunkedResponse = false;

    private string $buffer = '';

    private int $chunkLeft = 0;

    private bool $ended = false;

    /** @param resource $socket */
    private function __construct($socket)
    {
        $this->socket = $socket;
    }

    /**
     * Connect and send the request; null when the computer is not reachable there or refuses
     * the ticket (the caller then tries the next path).
     */
    public static function connect(string $address, string $ticket, string $mode, string $envelope, bool $willUpload, float $timeout = 1.5): ?self
    {
        if (!preg_match('/^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9.-]+):(\d{2,5})$/', $address)) {
            return null;
        }
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, $timeout);
        if (!is_resource($socket)) {
            return null;
        }
        stream_set_timeout($socket, 30);
        $channel = new self($socket);
        $head = "POST /mesh-files/v1 HTTP/1.1\r\nHost: {$address}\r\nContent-Type: application/octet-stream\r\n"
            . "Transfer-Encoding: chunked\r\nConnection: close\r\n\r\n";
        if (@fwrite($socket, $head) === false) {
            return null;
        }
        $channel->sendFrame('H', 0, (string) json_encode(['ticket' => $ticket, 'mode' => $mode, 'request' => base64_encode($envelope)]));
        if (!$willUpload) {
            $channel->endBody();
        }
        if (!$channel->readHead()) {
            $channel->close();

            return null;
        }

        return $channel;
    }

    private function sendFrame(string $type, int $flags, string $payload): void
    {
        $frame = $type . chr($flags) . pack('N', strlen($payload)) . $payload;
        if (@fwrite($this->socket, dechex(strlen($frame)) . "\r\n" . $frame . "\r\n") === false) {
            throw new ComputerException('direct_failed', 'The direct connection to the computer broke.');
        }
    }

    private function endBody(): void
    {
        if ($this->bodyOpen) {
            @fwrite($this->socket, "0\r\n\r\n");
            $this->bodyOpen = false;
        }
    }

    private function readHead(): bool
    {
        $status = fgets($this->socket);
        if (!is_string($status) || !preg_match('#^HTTP/1\.[01] 200#', $status)) {
            return false;
        }
        while (($line = fgets($this->socket)) !== false && trim($line) !== '') {
            if (stripos($line, 'transfer-encoding:') === 0 && stripos($line, 'chunked') !== false) {
                $this->chunkedResponse = true;
            }
        }

        return true;
    }

    /** n bytes of the response body (de-chunked), or null at its end. */
    private function readBody(int $n): ?string
    {
        while (strlen($this->buffer) < $n) {
            if ($this->ended) {
                return null;
            }
            if ($this->chunkedResponse && $this->chunkLeft === 0) {
                $line = fgets($this->socket);
                if ($line === false) {
                    $this->ended = true;
                    continue;
                }
                if (trim($line) === '') {
                    continue;
                }
                $this->chunkLeft = (int) hexdec(trim(explode(';', $line)[0]));
                if ($this->chunkLeft === 0) {
                    $this->ended = true;
                    continue;
                }
            }
            $want = $this->chunkedResponse ? min($this->chunkLeft, 65536) : 65536;
            $data = fread($this->socket, $want);
            if ($data === false || ($data === '' && feof($this->socket))) {
                $this->ended = true;
                continue;
            }
            if (stream_get_meta_data($this->socket)['timed_out'] ?? false) {
                throw new ComputerException('direct_timeout', 'The computer stopped answering on the direct connection.');
            }
            $this->buffer .= $data;
            if ($this->chunkedResponse) {
                $this->chunkLeft -= strlen($data);
            }
        }
        $out = substr($this->buffer, 0, $n);
        $this->buffer = (string) substr($this->buffer, $n);

        return $out;
    }

    /** Read one frame and file it; false at the end of the answer. */
    private function pump(): bool
    {
        $head = $this->readBody(6);
        if ($head === null || strlen($head) < 6) {
            return false;
        }
        $length = unpack('N', substr($head, 2, 4))[1];
        if ($length > Seal::CHUNK + 4096) {
            throw new ComputerException('direct_failed', 'The computer sent a frame that is too large.');
        }
        $payload = $length > 0 ? (string) $this->readBody($length) : '';
        match ($head[0]) {
            'R' => $this->responses[] = $payload,
            'D' => $this->downs[$this->downSeen++] = [$payload, (ord($head[1]) & 1) === 1],
            'X' => throw new ComputerException('direct_refused', 'The computer refused: ' . mb_substr($payload, 0, 200)),
            default => null,
        };

        return true;
    }

    public function response(int $from, int $wait): ?string
    {
        do {
            for ($i = count($this->responses) - 1; $i >= 0; $i--) {
                if (Seal::counterOf($this->responses[$i]) >= $from) {
                    return $this->responses[$i];
                }
            }
        } while ($this->pump());

        throw new ComputerException('direct_failed', 'The computer closed the direct connection without answering.');
    }

    public function putUp(int $n, string $sealed, bool $last): bool
    {
        $this->sendFrame('U', $last ? 1 : 0, $sealed);
        if ($last) {
            $this->endBody();
        }

        return true;
    }

    public function takeDown(int $n, int $wait): ?array
    {
        while (!isset($this->downs[$n])) {
            if (!$this->pump()) {
                throw new ComputerException('direct_failed', 'The direct connection ended before the whole file arrived.');
            }
        }
        $chunk = $this->downs[$n];
        unset($this->downs[$n]);

        return $chunk;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            $this->endBody();
            @fclose($this->socket);
        }
    }

    public function name(): string
    {
        return 'direct';
    }
}
