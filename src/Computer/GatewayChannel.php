<?php

namespace Elcreator\aMount\Computer;

/** A session relayed by ai.artur.work (always available: both sides only call out). */
final class GatewayChannel implements Channel
{
    private string $rev = '';

    /** @param array<string, mixed> $view what opening the session answered */
    public function __construct(private readonly Gateway $gateway, private readonly string $session, private array $view = [])
    {
    }

    public function session(): string
    {
        return $this->session;
    }

    /** The gateway's own state: QUEUED / ACTIVE / DONE / FAILED (+ error) / CLOSED. */
    public function view(int $wait = 0): array
    {
        $this->view = $this->gateway->state($this->session, $wait > 0 ? $this->rev : '', $wait);
        $this->rev = (string) ($this->view['rev'] ?? '');

        return $this->view;
    }

    public function response(int $from, int $wait): ?string
    {
        $deadline = microtime(true) + $wait;
        $view = $this->view;

        do {
            $sealed = isset($view['response']) && is_string($view['response']) ? base64_decode($view['response'], true) : false;
            if (is_string($sealed) && Seal::counterOf($sealed) >= $from) {
                return $sealed;
            }
            if (($view['state'] ?? '') === 'FAILED') {
                throw new ComputerException((string) ($view['error'] ?? 'failed'), match ((string) ($view['error'] ?? '')) {
                    'computer_offline' => 'The computer did not pick the request up - it is offline or asleep.',
                    'bad_seal' => 'The computer could not open the request (its key changed?).',
                    default => 'The computer could not take the request.',
                });
            }
            if (($view['state'] ?? '') === 'CLOSED') {
                throw new ComputerException('session_closed', 'The transfer was closed before the computer answered.');
            }
            $left = (int) ceil($deadline - microtime(true));
            if ($left <= 0) {
                return null;
            }
            $view = $this->view(min(15, $left));
        } while (true);
    }

    public function putUp(int $n, string $sealed, bool $last): bool
    {
        return $this->gateway->putUp($this->session, $n, $sealed, $last);
    }

    public function takeDown(int $n, int $wait): ?array
    {
        return $this->gateway->takeDown($this->session, $n, min(15, $wait));
    }

    public function close(): void
    {
        $this->gateway->close($this->session);
    }

    public function name(): string
    {
        return 'relay';
    }
}
