<?php

namespace Elcreator\aMount\Computer;

use RuntimeException;

/**
 * A refusal or failure from a computer or the gateway, with its code (`approval_pending`,
 * `conflict`, `name_not_allowed`, `quota_exceeded`, `disk_full`...) and whatever figures the
 * computer gave (`fingerprint`, `existingMtime`, `quota`, `used`). The message is meant for a
 * person and goes into the run's per-file result: a file that was not transferred always says
 * why.
 */
class ComputerException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(public readonly string $errorCode, string $message, int $status = 0, public readonly array $details = [])
    {
        parent::__construct($message, $status);
    }

    /** @param array<string, mixed> $answer a computer's `{ok:false, error, message, ...}` */
    public static function fromAnswer(array $answer): self
    {
        $details = array_diff_key($answer, array_flip(['ok', 'error', 'message']));

        return new self((string) ($answer['error'] ?? 'failed'), (string) ($answer['message'] ?? 'The computer refused.'), 0, $details);
    }
}
