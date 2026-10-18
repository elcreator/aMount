<?php

namespace Elcreator\aMount\Computer;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Throwable;

/**
 * A computer's shared folders as a Flysystem filesystem, so everything aMount does with a
 * store - rules, deliveries, fetches, recalls - works with "my computer" too.
 *
 * Paths are `<share>/<path>`, or just `<path>` when the mount names one share. The computer
 * decides everything that matters (which folders, which sites, read or write, conflicts,
 * quotas): a refusal becomes the exception Flysystem expects, carrying the computer's own
 * reason, so the run's per-file result says exactly why a file was not transferred.
 *
 * Writes are atomic on the computer (a temporary name, then a rename), which is why
 * {@see \Elcreator\aMount\Drivers\ComputerDriver::writesAtomically()} tells the syncer not to
 * "clean up" after a refused write - that would delete the computer's own, newer file.
 */
final class Adapter implements FilesystemAdapter
{
    /** @var array<string, array<string, mixed>> what list/stat/write told us, by full path */
    private array $known = [];

    public function __construct(private readonly Client $client, private readonly string $share = '')
    {
    }

    public function client(): Client
    {
        return $this->client;
    }

    /** @return array{0: string, 1: string} share and path inside it */
    private function split(string $path): array
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($this->share !== '') {
            return [$this->share, $path];
        }
        $parts = explode('/', $path, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private function entry(string $path): ?array
    {
        $path = trim($path, '/');
        if (!array_key_exists($path, $this->known)) {
            [$share, $rel] = $this->split($path);
            if ($share === '') {
                return ['path' => '', 'dir' => true, 'size' => 0, 'mtime' => 0];
            }
            $this->known[$path] = $this->client->stat($share, $rel);
        }

        return $this->known[$path];
    }

    public function fileExists(string $path): bool
    {
        try {
            $e = $this->entry($path);

            return $e !== null && empty($e['dir']);
        } catch (Throwable $e) {
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $e = $this->entry($path);

            return $e !== null && !empty($e['dir']);
        } catch (Throwable $e) {
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);
        $this->writeStream($path, $stream, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        [$share, $rel] = $this->split($path);
        $size = (int) ($config->get('amount_size') ?? $this->streamSize($contents));
        $mtime = (int) ($config->get('amount_mtime') ?? time());
        try {
            $answer = $this->client->write($share, $rel, $contents, $size, $mtime, (string) ($config->get('amount_on_conflict') ?? 'never'));
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
        $this->known[trim($path, '/')] = ['path' => $rel, 'size' => (int) ($answer['size'] ?? $size), 'mtime' => $mtime];
    }

    /** @param resource $stream */
    private function streamSize($stream): int
    {
        $stat = fstat($stream);
        $pos = ftell($stream);

        return is_array($stat) && isset($stat['size']) ? (int) $stat['size'] - (int) $pos : 0;
    }

    public function read(string $path): string
    {
        $out = '';
        $stream = $this->readStream($path);
        while (!feof($stream)) {
            $out .= (string) fread($stream, Seal::CHUNK);
        }
        fclose($stream);

        return $out;
    }

    public function readStream(string $path)
    {
        [$share, $rel] = $this->split($path);
        try {
            return ReadStream::open($this->client->readChunks($share, $rel));
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        [$share, $rel] = $this->split($path);
        try {
            $this->client->delete($share, $rel);
            unset($this->known[trim($path, '/')]);
        } catch (ComputerException $e) {
            if ($e->errorCode === 'not_found') {
                return;
            }
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, 'Folders on a computer are never deleted from a site.');
    }

    public function createDirectory(string $path, Config $config): void
    {
        [$share, $rel] = $this->split($path);
        try {
            $this->client->mkdir($share, $rel);
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'A computer has no public files.');
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'private');
    }

    public function mimeType(string $path): FileAttributes
    {
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'pdf' => 'application/pdf', 'zip' => 'application/zip', 'gz' => 'application/gzip', 'sql' => 'application/sql',
            'txt' => 'text/plain', 'csv' => 'text/csv', 'json' => 'application/json', 'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg'];

        return new FileAttributes($path, null, null, null, $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        $e = $this->metadata($path);

        return new FileAttributes($path, null, null, (int) $e['mtime']);
    }

    public function fileSize(string $path): FileAttributes
    {
        $e = $this->metadata($path);
        if (!empty($e['dir'])) {
            throw UnableToRetrieveMetadata::fileSize($path, 'it is a folder');
        }

        return new FileAttributes($path, (int) $e['size']);
    }

    private function metadata(string $path): array
    {
        try {
            $e = $this->entry($path);
        } catch (Throwable $ex) {
            throw UnableToRetrieveMetadata::create($path, 'metadata', $ex->getMessage(), $ex);
        }
        if ($e === null) {
            throw UnableToRetrieveMetadata::create($path, 'metadata', 'no such file on the computer');
        }

        return $e;
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $path = trim($path, '/');
        [$share, $rel] = $this->split($path);
        if ($share === '') {
            foreach ($this->client->shares() as $s) {
                yield new DirectoryAttributes((string) $s['name']);
            }

            return;
        }
        $prefix = $this->share === '' ? $share . '/' : '';
        $listing = $this->client->list($share, $rel, $deep, 5000);
        if ($listing['truncated']) {
            throw new UnableToReadFile('The folder holds more than 5000 entries; ask for a folder inside it.');
        }
        foreach ($listing['entries'] as $e) {
            $full = $prefix . $e['path'];
            $this->known[$full] = $e;
            yield !empty($e['dir'])
                ? new DirectoryAttributes($full, null, (int) $e['mtime'])
                : new FileAttributes($full, (int) $e['size'], null, (int) $e['mtime']);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
    }
}
