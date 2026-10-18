<?php

namespace Elcreator\aMount\Services;

use RuntimeException;

/**
 * A file that was deliberately not written - a name already taken (kept), a name this
 * server's disk cannot hold, a folder quota - as opposed to a failure. It still ends up in the
 * run's per-file result with its reason: nothing is skipped silently.
 */
final class TransferConflict extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
