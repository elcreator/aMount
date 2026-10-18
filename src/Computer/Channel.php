<?php

namespace Elcreator\aMount\Computer;

/**
 * The site's side of one session with a computer, whatever carries it: the gateway's relay,
 * this site's own mailbox (the computer connects here), the owner's own relay, or a direct
 * connection to the computer on the LAN. All of it sealed; a channel moves opaque bytes.
 */
interface Channel
{
    /** The computer's newest sealed answer with a counter of at least `$from`, or null within `$wait` seconds. */
    public function response(int $from, int $wait): ?string;

    /** Chunk n to the computer; false while it is a window behind (send it again shortly). */
    public function putUp(int $n, string $sealed, bool $last): bool;

    /** @return array{0: string, 1: bool}|null chunk n from the computer and whether it is the last */
    public function takeDown(int $n, int $wait): ?array;

    public function close(): void;

    /** "relay", "direct", "site mailbox", "own relay" - said in the run's log. */
    public function name(): string;
}
