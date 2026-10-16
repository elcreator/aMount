<?php

namespace Elcreator\aMount\Support;

use php_user_filter;
use RuntimeException;

/**
 * Raised from inside a stream filter to stop a copy that is already running.
 *
 * An exception, not a failed return code: a fatal filter result makes PHP
 * print "unprocessed buckets" warnings and hands the adapter a bare `false`,
 * which every Flysystem adapter words differently. An exception passes through
 * the adapter untouched and is told apart from a real I/O error by its class.
 */
final class TransferAborted extends RuntimeException
{
}

/**
 * Counts the bytes that pass through a stream, so a multi-gigabyte upload is
 * not a black box.
 *
 * Attached to the *source* stream, it sees every chunk the adapter reads —
 * whichever adapter, whichever chunk size — without wrapping the adapter or
 * buffering anything. The callback receives the size of each chunk and may
 * return `false` to abort the transfer (the run was cancelled): the filter then
 * throws {@see TransferAborted} out of the adapter's read loop.
 */
final class ProgressStream extends php_user_filter
{
    public const NAME = 'amount.progress';

    private static bool $registered = false;

    /**
     * @param resource $stream
     * @param callable(int): bool $onChunk return false to abort
     */
    public static function attach($stream, callable $onChunk): void
    {
        if (!self::$registered) {
            stream_filter_register(self::NAME, self::class);
            self::$registered = true;
        }

        stream_filter_append($stream, self::NAME, STREAM_FILTER_READ, ['callback' => $onChunk]);
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $callback = is_array($this->params) ? ($this->params['callback'] ?? null) : null;

        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;

            if (is_callable($callback) && $callback((int) $bucket->datalen) === false) {
                throw new TransferAborted('The transfer was cancelled.');
            }

            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
