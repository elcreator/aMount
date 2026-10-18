<?php

namespace Elcreator\aMount\Computer;

use Generator;

/**
 * A readable PHP stream over a computer's file as it arrives (`amount-computer://`), so a
 * five-gigabyte backup flows to its destination without being held in memory or copied to a
 * temporary file first. Dropping the stream drops the generator, which closes the session.
 */
final class ReadStream
{
    public const SCHEME = 'amount-computer';

    /** @var array<string, Generator<int, string>> */
    private static array $pending = [];

    /** @var resource|null set by PHP */
    public $context;

    private ?Generator $chunks = null;

    private string $buffer = '';

    private bool $eof = false;

    /** @return resource */
    public static function open(Generator $chunks)
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        $id = bin2hex(random_bytes(8));
        self::$pending[$id] = $chunks;
        $stream = fopen(self::SCHEME . '://' . $id, 'rb');
        unset(self::$pending[$id]);
        if ($stream === false) {
            throw new ComputerException('stream_failed', 'The file from the computer could not be opened as a stream.');
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $id = (string) parse_url($path, PHP_URL_HOST);
        if (!isset(self::$pending[$id])) {
            return false;
        }
        $this->chunks = self::$pending[$id];

        return true;
    }

    public function stream_read(int $count): string|false
    {
        while (strlen($this->buffer) < $count && !$this->eof) {
            if (!$this->chunks->valid()) {
                $this->eof = true;
                break;
            }
            $this->buffer .= (string) $this->chunks->current();
            $this->chunks->next(); // a failure here (a refused or broken transfer) propagates to the reader
        }
        $out = substr($this->buffer, 0, $count);
        $this->buffer = (string) substr($this->buffer, strlen($out));

        return $out;
    }

    public function stream_eof(): bool
    {
        return $this->eof && $this->buffer === '';
    }

    public function stream_stat(): array|false
    {
        return [];
    }

    public function stream_close(): void
    {
        $this->chunks = null;
    }
}
