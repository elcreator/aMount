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

    /** destination(): check everything but an existing file - that is decided per file while the run works. */
    public const LATER = 'later';

    /** Windows device names: `CON`, `con.txt`, `LPT1.tar.gz` cannot be created there. */
    private const WINDOWS_DEVICES = ['con', 'prn', 'aux', 'nul', 'com0', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8',
        'com9', 'lpt0', 'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9'];

    /** Whose naming rules apply (a property so tests can play Windows on Linux). */
    public static string $hostOs = PHP_OS_FAMILY;

    /**
     * Why this server's filesystem cannot hold `$name`, or null. Said out loud: a file that
     * cannot be written is a failure with a reason, never a silent skip.
     */
    public static function nameProblem(string $name): ?string
    {
        if (strlen($name) > 255) {
            return '"' . mb_substr($name, 0, 40) . '..." is longer than 255 bytes.';
        }
        if (self::$hostOs === 'Windows') {
            if (preg_match('/[<>:"|?*\\x00-\\x1F]/', $name)) {
                return '"' . $name . '" holds a character Windows does not allow (< > : " | ? * or a control character).';
            }
            if (preg_match('/[. ]$/', $name)) {
                return '"' . $name . '" ends with a dot or a space, which Windows drops.';
            }
            $stem = strtolower(explode('.', rtrim($name, '. '))[0]);
            if (in_array(trim($stem), self::WINDOWS_DEVICES, true)) {
                return '"' . $name . '" is a name Windows reserves (CON, PRN, AUX, NUL, COM1-9, LPT1-9).';
            }
        }

        return null;
    }

    /**
     * On a case-insensitive disk (Windows, macOS) `Photo.jpg` and `photo.jpg` are one file:
     * the existing entry's real name when it differs from `$name` only in case, else null.
     */
    public static function caseTwin(string $directory, string $name): ?string
    {
        if (!in_array(self::$hostOs, ['Windows', 'Darwin'], true) || !is_dir($directory)) {
            return null;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== $name && strcasecmp($entry, $name) === 0) {
                return $entry;
            }
        }

        return null;
    }

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
    public static function destination(Scope $scope, FileEntry $entry, ?string $to, bool|string $overwrite): string
    {
        $name = basename(str_replace('\\', '/', (string) $entry->local_path));

        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('The stored file has no usable name.');
        }

        if (($problem = static::nameProblem($name)) !== null) {
            throw new TransferConflict('name_not_allowed', $problem);
        }

        if (static::isExecutableName($name) && !static::mayEditCode($scope->principal())) {
            throw new RuntimeException('"' . $name . '" is an executable or server-configuration file and can only be restored by someone who may edit code.');
        }

        if ($to === null || trim($to) === '') {
            $folder = dirname(str_replace('\\', '/', (string) $entry->local_path));
            $folder = $folder === '.' ? '' : $folder;
        } elseif (str_contains(str_replace('\\', '/', (string) $entry->local_path), '/') && str_starts_with(str_replace('\\', '/', (string) $entry->local_path), rtrim((string) Paths::clean($to), '/') . '/')) {
            // A recursive fetch: the entry already names its subfolder under the destination.
            $folder = dirname(str_replace('\\', '/', (string) $entry->local_path));
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

        if (($twin = static::caseTwin($directory, $name)) !== null) {
            throw new TransferConflict('case_collision', '"' . $twin . '" is already in "' . $folder . '", and this server\'s disk treats it as the same name as "'
                . $name . '" - nothing was replaced.');
        }

        if (!file_exists($target) || $overwrite === self::LATER) {
            return $target;
        }

        if ($overwrite === 'skip') {
            throw new TransferConflict('conflict', '"' . $name . '" is already in "' . $folder . '" - kept (replacing files is not allowed for this transfer).');
        }

        if ($overwrite === 'newer') {
            $here = (int) @filemtime($target);
            if ((int) $entry->mtime <= $here) {
                throw new TransferConflict('conflict', '"' . $name . '" in "' . $folder . '" is newer here (or as new) - kept.');
            }
            if (!$scope->canModify($target)) {
                throw new RuntimeException('"' . $name . '" already exists in "' . $folder . '" and you may not replace it.');
            }

            return $target;
        }

        if (!$overwrite || !$scope->canModify($target)) {
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
        $overwrite = in_array($payload['on_conflict'] ?? null, ['skip', 'newer'], true) ? (string) $payload['on_conflict'] : !empty($payload['overwrite']);
        $deleteRemote = !empty($payload['delete_remote']);
        $quota = isset($payload['quota']) ? (int) $payload['quota'] : null;
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
                    $results[$id] = $this->restore($entry, $to, $overwrite, $deleteRemote, $quota);
                } catch (TransferConflict $e) {
                    $results[$id] = ['ok' => false, 'skipped' => true, 'code' => $e->errorCode, 'error' => mb_substr($e->getMessage(), 0, 500)];
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
        $skipped = count(array_filter($results, static fn ($r) => !empty($r['skipped'])));
        $message = 'Downloaded ' . $done . ' file(s)'
            . ($skipped > 0 ? '; ' . $skipped . ' not written (conflict or name): ' . $this->firstError(array_filter($results, static fn ($r) => !empty($r['skipped']))) : '')
            . ($failed - $skipped > 0 ? '; ' . ($failed - $skipped) . ' failed: ' . $this->firstError(array_filter($results, static fn ($r) => empty($r['ok']) && empty($r['skipped']))) : '')
            . '.';

        Runs::advance($this->run, [
            'status' => $failed > 0 && $done === 0 ? Run::FAILED : Run::DONE,
            'message' => mb_substr($message, 0, 500),
            'finished_at' => now(),
        ]);
        $this->emit($message, $failed > 0 && $done === 0 ? 'error' : 'info');

        return true;
    }

    /** Bytes in the destination folder, measured once per run and kept up to date. */
    private ?int $folderUsed = null;

    /** @return array{ok: bool, error?: string, warning?: string} */
    private function restore(FileEntry $entry, ?string $to, bool|string $overwrite, bool $deleteRemote, ?int $quota = null): array
    {
        if ($entry->status !== FileEntry::UPLOADED) {
            return ['ok' => false, 'error' => 'The file is not stored remotely.'];
        }

        // Re-validated here, as the user stands now, not as they stood when they asked.
        $target = static::destination($this->scope, $entry, $to, $overwrite);

        // The site folder's quota (always set for files from a computer): checked before a
        // byte moves, and the computer is told it, so both sides know each other's limits.
        if ($quota !== null && $to !== null) {
            $this->folderUsed ??= static::folderBytes(Paths::absolute((string) Paths::clean($to)));
            $replacing = is_file($target) ? (int) filesize($target) : 0;
            if ($this->folderUsed - $replacing + (int) $entry->size > $quota) {
                throw new TransferConflict('quota_exceeded', '"' . basename($target) . '" would take "' . $to . '" over its quota of '
                    . round($quota / 1048576) . ' MB (' . round($this->folderUsed / 1048576) . ' MB used).');
            }
            \Elcreator\aMount\Computer\Client::$destination = ['folder' => (string) $to, 'quota' => $quota, 'used' => $this->folderUsed];
        }

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

        if ($quota !== null && $this->folderUsed !== null) {
            $this->folderUsed += (int) $entry->size;
        }
        \Elcreator\aMount\Computer\Client::$destination = null;

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

    /** Total size of the files under a folder (the quota's "used"). */
    public static function folderBytes(string $absolute): int
    {
        if (!is_dir($absolute)) {
            return 0;
        }
        $total = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $total += $file->getSize();
            }
        }

        return $total;
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
