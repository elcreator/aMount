<?php

namespace Elcreator\aMount\Services;

use Closure;
use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Support\Paths;
use Elcreator\aMount\Support\Principal;
use Elcreator\aMount\Support\ProgressStream;
use Elcreator\aMount\Support\Scope;
use Elcreator\aMount\Support\TransferAborted;
use League\Flysystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * Brings offloaded files back ("restore that backup").
 *
 * Writing into the site is the dangerous direction, so the rules are strict and
 * all of them live in {@see destination()} and {@see download()}, which are
 * plain functions of their arguments and can be tested without a queue:
 *
 *  - the destination must be somewhere the asking user can see AND add files to;
 *  - an executable or server-configuration file name is refused unless that user
 *    may edit code — a stored `shell.php` must not become a way around the
 *    code permissions;
 *  - an existing file is never replaced unless asked, and then only if the user
 *    could modify it anyway;
 *  - the bytes go to `<name>.amount-part`, their size is verified, and only then
 *    is the name taken, so a crash never leaves a half-file under the real name;
 *  - the file's mtime is set to the recorded one, so the next scan sees the
 *    restored file as unchanged and does not upload it again.
 *
 * There is deliberately no database restore here: the CMS forbids queued
 * restores, so a downloaded dump is restored by its own Backup manager.
 */
final class Transfer
{
    /** Extensions a web server may execute or treat as configuration. */
    private const EXECUTABLE = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
        'shtml', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'dll', 'htaccess',
    ];

    private const RESERVED_NAMES = ['.htaccess', '.htpasswd', '.user.ini', 'web.config', 'php.ini', '.env', 'ng.inx', 'ht.access', 'caddyfile'];

    private ?Closure $report;

    public function __construct(
        private Run $run,
        private Rule $rule,
        private Mount $mount,
        private Principal $principal,
        private Scope $scope,
        private Filesystem $fs,
        ?callable $report = null,
        private float $heartbeatEvery = 2.0
    ) {
        $this->report = $report === null ? null : Closure::fromCallable($report);
    }

    // ------------------------------------------------------------------
    // Pure rules
    // ------------------------------------------------------------------

    /** Whether a file name could run as code or reconfigure the server. */
    public static function isExecutableName(string $name): bool
    {
        $name = strtolower(trim($name));

        if ($name === '' || in_array($name, self::RESERVED_NAMES, true) || str_starts_with($name, '.ht')) {
            return true;
        }

        // Every dot-separated part, not just the last: `shell.php.jpg` runs on
        // servers that map handlers by any extension.
        foreach (array_slice(explode('.', $name), 1) as $extension) {
            if (in_array(trim($extension), self::EXECUTABLE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where a stored file would be written, or why it must not be.
     *
     * @param string|null $to site folder; null = where the file came from
     * @return string absolute path of the file to write
     *
     * @throws RuntimeException with a plain-English reason
     */
    public static function destination(Scope $scope, FileEntry $entry, ?string $to, bool $overwrite): string
    {
        $name = basename(str_replace('\\', '/', (string) $entry->local_path));

        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('The stored file has no usable name.');
        }

        if (static::isExecutableName($name) && !static::mayEditCode($scope->principal())) {
            throw new RuntimeException('"' . $name . '" is an executable or server-configuration file and can only be restored by someone who may edit code.');
        }

        if ($to === null || trim($to) === '') {
            $folder = dirname(str_replace('\\', '/', (string) $entry->local_path));
            $folder = $folder === '.' ? '' : $folder;
        } else {
            $folder = Paths::clean($to);

            if ($folder === null) {
                throw new RuntimeException('The destination folder is not a valid path.');
            }
        }

        $directory = Paths::absolute($folder);
        $target = rtrim($directory, '/') . '/' . $name;

        // Rules that need no folder to exist: root, protected folders, file groups.
        if (($reason = $scope->denyFile($target)) !== null) {
            throw new RuntimeException('The destination "' . $folder . '" is not available to you (' . $reason . ').');
        }

        // The folder may have been removed by a `move` rule; it is recreated, but
        // only below a folder the user could have created it in themselves.
        $probe = $directory;

        while (!is_dir($probe) && $probe !== dirname($probe)) {
            $probe = dirname($probe);
        }

        if ($scope->denyDirectory($probe) !== null || !$scope->canCreateIn($probe)) {
            throw new RuntimeException('You may not add files to "' . $folder . '".');
        }

        if (is_link($target) || is_dir($target)) {
            throw new RuntimeException('"' . $name . '" cannot be written: a link or folder is in the way.');
        }

        if (file_exists($target) && (!$overwrite || !$scope->canModify($target))) {
            throw new RuntimeException('"' . $name . '" already exists in "' . $folder . '"' . ($overwrite ? ' and you may not replace it.' : '.'));
        }

        return $target;
    }

    /**
     * Stream one remote file to `$absolute` through `<name>.amount-part`.
     *
     * @param callable(int): bool|null $progress return false to abort
     *
     * @throws RuntimeException|TransferAborted
     */
    public static function download(Filesystem $fs, string $remotePath, string $absolute, ?int $mtime = null, bool $overwrite = false, ?callable $progress = null): void
    {
        if (file_exists($absolute) && !$overwrite) {
            throw new RuntimeException('"' . basename($absolute) . '" already exists.');
        }

        $directory = dirname($absolute);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The folder "' . basename($directory) . '" cannot be created.');
        }

        $part = $absolute . '.amount-part';

        if (is_link($part)) {
            throw new RuntimeException('A link is in the way of the temporary file.');
        }

        if (file_exists($part)) {
            @unlink($part);
        }

        $expected = (int) $fs->fileSize($remotePath);
        $in = $fs->readStream($remotePath);
        $out = @fopen($part, 'wb');

        if (!is_resource($out)) {
            if (is_resource($in)) {
                fclose($in);
            }

            throw new RuntimeException('The file cannot be written to "' . basename($directory) . '".');
        }

        if ($progress !== null) {
            ProgressStream::attach($in, $progress);
        }

        try {
            if (stream_copy_to_stream($in, $out) === false) {
                throw new RuntimeException('The download was interrupted.');
            }
        } catch (Throwable $e) {
            fclose($out);
            is_resource($in) && fclose($in);
            @unlink($part);

            throw $e;
        }

        fclose($out);
        is_resource($in) && fclose($in);
        clearstatcache(true, $part);

        $got = (int) filesize($part);

        if ($got !== $expected) {
            @unlink($part);

            throw new RuntimeException('Size mismatch after download: expected ' . $expected . ' bytes, got ' . $got . '.');
        }

        if (!@rename($part, $absolute)) {
            @unlink($part);

            throw new RuntimeException('The downloaded file could not be moved into place.');
        }

        if ($mtime !== null && $mtime > 0) {
            @touch($absolute, $mtime);
        }
    }

    private static function mayEditCode(Principal $principal): bool
    {
        foreach (['save_snippet', 'save_plugin', 'save_module'] as $permission) {
            if ($principal->can($permission)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // The run
    // ------------------------------------------------------------------

    /** @return bool true = the run reached a final state */
    public function work(?float $deadline = null): bool
    {
        $payload = is_array($this->run->payload_json) ? $this->run->payload_json : [];
        $ids = array_values(array_map('intval', (array) ($payload['entries'] ?? [])));
        $results = (array) ($payload['results'] ?? []);
        $to = isset($payload['to']) && $payload['to'] !== '' ? (string) $payload['to'] : null;
        $overwrite = !empty($payload['overwrite']);
        $deleteRemote = !empty($payload['delete_remote']);
        $processed = 0;

        if (!Runs::advance($this->run, [
            'status' => Run::UPLOADING,
            'started_at' => $this->run->started_at ?? now(),
            'files_total' => count($ids),
            'heartbeat_at' => now(),
        ])) {
            return true;
        }

        foreach ($ids as $id) {
            if (isset($results[$id])) {
                continue;
            }

            if (Runs::stopped($this->run)) {
                return true;
            }

            if ($processed > 0 && $deadline !== null && microtime(true) >= $deadline) {
                return false;
            }

            $processed++;
            $entry = FileEntry::query()->whereKey($id)->where('rule_id', $this->rule->getKey())->first();

            if ($entry === null) {
                $results[$id] = ['ok' => false, 'error' => 'The file is no longer known to aMount.'];
            } else {
                try {
                    $results[$id] = $this->restore($entry, $to, $overwrite, $deleteRemote);
                } catch (TransferAborted $e) {
                    return true;
                } catch (Throwable $e) {
                    if (Runs::stopped($this->run)) {
                        return true;
                    }

                    $results[$id] = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500)];
                }
            }

            $done = count(array_filter($results, static fn ($r) => !empty($r['ok'])));
            $failed = count($results) - $done;
            $bytes = (int) FileEntry::query()->whereIn('id', array_keys($results))->sum('size');

            $payload['results'] = $results;

            if (!Runs::advance($this->run, [
                'payload_json' => $payload,
                'files_done' => $done,
                'files_failed' => $failed,
                'bytes_done' => $bytes,
                'current_file' => null,
                'heartbeat_at' => now(),
            ])) {
                return true;
            }

            $this->emit(!empty($results[$id]['ok']) ? 'Downloaded ' . basename((string) ($entry->local_path ?? '')) : (string) ($results[$id]['error'] ?? ''), empty($results[$id]['ok']) ? 'warning' : 'info');
        }

        $done = (int) $this->run->files_done;
        $failed = (int) $this->run->files_failed;
        $message = 'Downloaded ' . $done . ' file(s)' . ($failed > 0 ? '; ' . $failed . ' failed: ' . $this->firstError($results) : '.');

        Runs::advance($this->run, [
            'status' => $failed > 0 && $done === 0 ? Run::FAILED : Run::DONE,
            'message' => mb_substr($message, 0, 500),
            'finished_at' => now(),
        ]);
        $this->emit($message, $failed > 0 && $done === 0 ? 'error' : 'info');

        return true;
    }

    /** @return array{ok: bool, error?: string, warning?: string} */
    private function restore(FileEntry $entry, ?string $to, bool $overwrite, bool $deleteRemote): array
    {
        if ($entry->status !== FileEntry::UPLOADED) {
            return ['ok' => false, 'error' => 'The file is not stored remotely.'];
        }

        // Re-validated here, as the user stands now, not as they stood when they asked.
        $target = static::destination($this->scope, $entry, $to, $overwrite);

        $lastBeat = microtime(true);
        $read = 0;
        $base = (int) $this->run->bytes_done;
        $file = (string) $entry->local_path;

        static::download($this->fs, $entry->remote_path, $target, (int) $entry->mtime, $overwrite, function (int $chunk) use (&$read, &$lastBeat, $base, $file): bool {
            $read += $chunk;

            if (microtime(true) - $lastBeat < $this->heartbeatEvery) {
                return true;
            }

            $lastBeat = microtime(true);
            $this->emit('Downloading ' . basename($file), 'info', ['file' => $file, 'bytes_done' => $base + $read]);

            return Runs::advance($this->run, ['bytes_done' => $base + $read, 'current_file' => mb_substr($file, 0, 1000), 'heartbeat_at' => now()]);
        });

        $result = ['ok' => true];
        $update = [];

        if (rtrim($target, '/') === rtrim(Paths::absolute($entry->local_path), '/')) {
            // Back where it was: index it as present, so a `move` rule's next scan
            // treats it as unchanged rather than new.
            $update += ['local_removed' => false, 'seen_at' => now()];
        }

        if ($deleteRemote) {
            try {
                $this->fs->delete($entry->remote_path);
                $update += ['status' => FileEntry::EXPIRED, 'error' => null];
            } catch (Throwable $e) {
                $result['warning'] = 'Downloaded, but the remote copy could not be deleted.';
            }
        }

        if ($update !== []) {
            $entry->forceFill($update)->save();
        }

        return $result;
    }

    private function firstError(array $results): string
    {
        foreach ($results as $result) {
            if (empty($result['ok'])) {
                return (string) ($result['error'] ?? 'unknown error');
            }
        }

        return '';
    }

    private function emit(string $message, string $level = 'info', array $context = []): void
    {
        if ($this->report !== null) {
            ($this->report)(Run::UPLOADING, $this->run->percent(), mb_substr($message, 0, 255), $level, $context + ['run_uuid' => (string) $this->run->uuid]);
        }
    }
}
