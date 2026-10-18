<?php

namespace Elcreator\aMount\Computer;

use Closure;
use Throwable;

/**
 * One operation with one computer, over the best path there is (docs/features/29):
 *
 *  1. straight to the computer, when it listens and this site reaches it (LAN, Docker, a
 *     forwarded port) - asked for with a short-lived gateway ticket;
 *  2. otherwise through the gateway, with this site's own mailbox and the owner's own relay
 *     offered **inside the seal**: the computer takes the first it reaches and says "moved";
 *  3. otherwise the gateway's relay carries it.
 *
 * Every answer is sealed by the computer; a refusal comes back as a ComputerException with
 * the computer's code and reason, so no file is ever skipped silently.
 */
final class Link
{
    private const ANSWER_WAIT = 90;       // a computer picks a request up within seconds
    private const ASK_WAIT = 660;         // the person at the computer has ten minutes to decide
    private const STALL = 600;            // no chunk for this long: the transfer is dead

    private ?Closure $log;

    /** @param array<string, mixed> $computer one entry of GET /computers */
    public function __construct(
        private readonly Gateway $gateway,
        private readonly array $computer,
        private readonly string $sitePrivate,
        private readonly ?string $mailboxUrl = null,
        private readonly ?string $relayUrl = null,
        private readonly string $siteName = '',
        ?callable $log = null,
        private readonly bool $tryDirect = true,
    ) {
        $this->log = $log === null ? null : Closure::fromCallable($log);
    }

    /** A request with one answer: shares, list, stat, mkdir, delete. @return array<string, mixed> */
    public function call(string $mode, array $request): array
    {
        return $this->session($mode, $request, false, function (Seal $seal, Channel $ch, ?array $first) {
            return $this->final($seal, $ch, $first, self::ANSWER_WAIT);
        });
    }

    /**
     * Read one file, piece by piece, as the pieces arrive. Stopping early (a cancelled run)
     * closes the session: the generator's `finally` runs when it is dropped.
     *
     * @return \Generator<int, string> plaintext pieces; the computer's final answer is the return value
     */
    public function readChunks(array $request): \Generator
    {
        [$seal, $ch, $first, $cleanup] = $this->open('read', $request + ['op' => 'read'], false);
        try {
            $head = $first ?? $this->answer($seal, $ch, self::ANSWER_WAIT);
            $this->refuseIfNotOk($head);
            $chunks = max(1, (int) ($head['chunks'] ?? 1));
            for ($n = 0; $n < $chunks; $n++) {
                [$sealed, $last] = $this->chunk($ch, $n);
                if ($last !== ($n === $chunks - 1)) {
                    throw new ComputerException('integrity', 'The file arrived with a missing or extra part.');
                }
                yield $seal->openDown($n, $last, $sealed);
            }

            // The gateway keeps only the computer's latest answer: the closing one (which repeats
            // size and chunks) may be all this side ever saw.
            $final = !empty($head['complete']) ? $head : $this->final($seal, $ch, null, self::ANSWER_WAIT);

            return $final + ['size' => (int) ($head['size'] ?? 0), 'mtime' => (int) ($head['mtime'] ?? 0)];
        } finally {
            $cleanup();
        }
    }

    /** Read one file into `$sink(string $plain)`. @return array<string, mixed> the final answer */
    public function read(array $request, callable $sink): array
    {
        $chunks = $this->readChunks($request);
        foreach ($chunks as $piece) {
            $sink($piece);
        }

        return (array) $chunks->getReturn();
    }

    /**
     * Write one file to a share; `$source()` returns the next plaintext piece ('' at the end).
     *
     * @return array<string, mixed> the computer's final answer (written, replaced, unchanged)
     */
    public function write(array $request, callable $source): array
    {
        return $this->session('write', $request + ['op' => 'write'], true, function (Seal $seal, Channel $ch, ?array $first) use ($source, $request) {
            $answer = $first;
            while (true) {
                $answer ??= $this->answer($seal, $ch, self::ASK_WAIT);
                $status = (string) ($answer['status'] ?? '');
                if ($status === 'asking') {
                    $this->note('The person at the computer is asked whether to replace ' . ($request['path'] ?? 'the file') . '.');
                    $answer = null;
                    continue;
                }
                if ($status !== 'ready') {
                    $this->refuseIfNotOk($answer);

                    return $answer; // decided without the bytes: "unchanged"
                }
                break;
            }
            $n = 0;
            $piece = (string) $source();
            do {
                $next = $piece === '' ? '' : (string) $source();
                $last = $next === '';
                $sealed = $seal->sealUp($n, $last, $piece);
                $started = microtime(true);
                while (!$ch->putUp($n, $sealed, $last)) {
                    if (microtime(true) - $started > self::STALL) {
                        throw new ComputerException('stalled', 'The computer stopped taking the file.');
                    }
                    usleep(300000);
                }
                $n++;
                $piece = $next;
            } while (!$last);

            return $this->final($seal, $ch, null, self::ASK_WAIT);
        });
    }

    // ------------------------------------------------------------------

    private function session(string $mode, array $request, bool $upload, callable $work): array
    {
        [$seal, $channel, $first, $cleanup] = $this->open($mode, $request, $upload);
        try {
            return $work($seal, $channel, $first);
        } finally {
            $cleanup();
        }
    }

    /** @return array{0: Seal, 1: Channel, 2: ?array, 3: Closure} the keys, the path, a first answer, and how to close it all */
    private function open(string $mode, array $request, bool $upload): array
    {
        $request['site'] = ['name' => $this->siteName];
        $local = null;
        $remote = null;
        $paths = [];
        if ($this->mailboxUrl !== null) {
            try {
                $local = LocalMailbox::create();
                $paths[] = ['url' => rtrim($this->mailboxUrl, '/') . '/' . rtrim(LocalMailbox::PREFIX, '/'), 'token' => $local->token()];
            } catch (Throwable) {
                $local = null;
            }
        }
        if ($this->relayUrl !== null) {
            $base = rtrim($this->relayUrl, '/');
            $base = str_ends_with($base, '/mailbox') ? $base : $base . '/mailbox';
            $token = RemoteMailbox::newToken();
            $remote = new RemoteMailbox($base, $token);
            $paths[] = ['url' => $base, 'token' => $token];
        }
        if ($paths !== []) {
            $request['paths'] = $paths;
        }
        [$seal, $envelope] = Seal::request($this->sitePrivate, Seal::fromB64url((string) $this->computer['publicKey']), $request);

        $channel = null;
        $gateway = null;
        $cleanup = static function () use (&$channel, &$gateway, $local): void {
            $channel?->close();
            if ($gateway !== null && $channel !== $gateway) {
                $gateway->close();
            }
            $local?->close();
        };
        try {
            $channel = $this->direct($mode, $envelope, $upload);
            $first = null;
            if ($channel === null) {
                $view = $this->gateway->open((int) $this->computer['id'], $mode, $envelope, 10);
                $gateway = new GatewayChannel($this->gateway, (string) $view['session'], $view);
                $channel = $gateway;
                $first = $this->answer($seal, $gateway, self::ANSWER_WAIT);
                if (($first['status'] ?? '') === 'moved') {
                    $channel = ($local !== null && $local->hasHello()) ? $local : (($remote !== null && $remote->hasHello()) ? $remote : null);
                    if ($channel === null) {
                        $channel = $gateway;
                        throw new ComputerException('moved_lost', 'The computer moved the transfer to a mailbox that does not answer here.');
                    }
                    $first = null;
                }
            }
            $this->note('Path to the computer: ' . $channel->name() . '.');
        } catch (Throwable $e) {
            $cleanup();
            throw $e;
        }

        return [$seal, $channel, $first, $cleanup];
    }

    /** The computer itself, when it listens and answers here. */
    private function direct(string $mode, string $envelope, bool $upload): ?DirectChannel
    {
        $addresses = array_merge((array) ($this->computer['direct'] ?? []), (array) ($this->computer['advertised'] ?? []));
        if (!$this->tryDirect || $addresses === []) {
            return null;
        }
        try {
            $ticket = $this->gateway->ticket((int) $this->computer['id'], $mode);
        } catch (Throwable) {
            return null;
        }
        foreach ((array) ($ticket['addresses'] ?? $addresses) as $address) {
            $channel = DirectChannel::connect((string) $address, (string) $ticket['ticket'], $mode, $envelope, $upload);
            if ($channel !== null) {
                return $channel;
            }
        }

        return null;
    }

    /** The computer's next answer. @return array<string, mixed> */
    private function answer(Seal $seal, Channel $ch, int $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $sealed = $ch->response($seal->nextResponse(), (int) min(15, max(1, $deadline - microtime(true))));
            if ($sealed !== null) {
                return $seal->openResponse($sealed);
            }
        }
        throw new ComputerException('no_answer', 'The computer did not answer in time.');
    }

    /** Wait for the answer that ends the operation (skipping "asking"/"ready"). */
    private function final(Seal $seal, Channel $ch, ?array $first, int $timeout): array
    {
        $answer = $first;
        while (true) {
            $answer ??= $this->answer($seal, $ch, $timeout);
            if (!in_array($answer['status'] ?? '', ['asking', 'ready'], true)) {
                break;
            }
            $answer = null;
        }
        $this->refuseIfNotOk($answer);

        return $answer;
    }

    /** @return array{0: string, 1: bool} */
    private function chunk(Channel $ch, int $n): array
    {
        $started = microtime(true);
        while (microtime(true) - $started < self::STALL) {
            $got = $ch->takeDown($n, 15);
            if ($got !== null) {
                return $got;
            }
        }
        throw new ComputerException('stalled', 'The computer stopped sending the file.');
    }

    private function refuseIfNotOk(array $answer): void
    {
        if (($answer['ok'] ?? false) !== true) {
            throw ComputerException::fromAnswer($answer);
        }
    }

    private function note(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($message);
        }
    }
}
