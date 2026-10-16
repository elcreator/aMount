<?php

namespace Elcreator\aMount\Services;

use Elcreator\aMount\Support\Lang;
use RuntimeException;

/**
 * A refusal from the module, in the one shape every surface reports it.
 *
 * `error` is the stable machine code a page or an AI agent switches on,
 * `status` the HTTP status the controllers answer with, and the message is
 * already translated. `errors` (field => message) and `candidates` (the mounts
 * an ambiguous request could mean) travel with it so a caller can ask a useful
 * follow-up question instead of guessing. Nothing in here ever carries a secret.
 */
class AmountException extends RuntimeException
{
    /** @var array<string, string> */
    public array $errors = [];

    /** @var array<int, array<string, mixed>> */
    public array $candidates = [];

    public function __construct(
        public readonly string $error,
        string $message,
        public readonly int $status = 422
    ) {
        parent::__construct($message);
    }

    /** Translated text that is safe outside a request; see {@see Lang::get()}. */
    public static function text(string $key, array $replace = []): string
    {
        return Lang::get($key, $replace);
    }

    public static function forbidden(): self
    {
        return new self('forbidden', AmountException::text('aMount::global.error_forbidden'), 403);
    }

    /** Also the answer for "exists but is not yours": a stranger's mount must look like a missing one. */
    public static function notFound(string $what): self
    {
        return new self('not_found', AmountException::text('aMount::global.error_not_found', ['what' => $what]), 404);
    }

    public static function invalid(string $key, array $replace = []): self
    {
        return new self($key, AmountException::text('aMount::global.' . $key, $replace), 422);
    }

    /** @param array<string, string> $errors field => already translated message */
    public static function fields(array $errors): self
    {
        $e = new self('invalid', AmountException::text('aMount::global.error_invalid'), 422);
        $e->errors = $errors;

        return $e;
    }

    /** @param array<int, array<string, mixed>> $candidates */
    public static function ambiguous(array $candidates): self
    {
        $names = implode(', ', array_map(static fn ($c) => (string) ($c['name'] ?? ''), $candidates));
        $e = new self('ambiguous_mount', AmountException::text('aMount::global.error_ambiguous_mount', ['names' => $names]), 409);
        $e->candidates = $candidates;

        return $e;
    }

    public function toArray(): array
    {
        return array_filter([
            'error' => $this->error,
            'message' => $this->getMessage(),
            'errors' => $this->errors ?: null,
            'candidates' => $this->candidates ?: null,
        ], static fn ($v) => $v !== null);
    }
}
