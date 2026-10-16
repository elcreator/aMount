<?php

namespace Elcreator\aMount\Services;

use Closure;
use Elcreator\aMount\Drivers\Drivers;
use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Support\Config;
use Elcreator\aMount\Support\Paths;
use Elcreator\aMount\Support\Principal;
use Elcreator\aMount\Support\ProgressStream;
use Elcreator\aMount\Support\Scope;
use Elcreator\aMount\Support\TransferAborted;
use League\Flysystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * Carries out one run of a rule: scan the folder, upload what is new or changed,
 * verify, and — for `move` rules — remove the local copy.
 *
 * Three promises hold everywhere in here:
 *
 *  - **Visibility is asked again.** The rule's owner is re-read from the
 *    database at the start of every slice and every file is checked against
 *    {@see Scope} again right before it is opened. A rule that was allowed when
 *    it was saved never gets more than its owner can see *now*.
 *  - **A step can run twice.** A worker may die anywhere. Uploads go to the
 *    final key (overwriting is idempotent), and a local file is deleted only
 *    after the remote size matches AND the local file is unchanged since it was
 *    read.
 *  - **A cancel wins.** Every write goes through {@see Runs::advance()}, which
 *    refuses once the run is no longer active, and the progress filter aborts
 *    the copy in flight.
 *
 * Downloads (`direction=down`) are handed to {@see Transfer}.
 */
final class Syncer
{
    /** Seconds between heartbeat/progress writes while a file streams. Lowered by tests. */
    public float $heartbeatEvery = 2.0;

    private ?Closure $report;

    /** @param callable(string, int, string, string, array): void|null $report step, percent, message, level, context */
    public function __construct(private Run $run, ?callable $report = null)
    {
        $this->report = $report === null ? null : Closure::fromCallable($report);
    }

    /**
     * Work for at most about $budgetSeconds, never abandoning a file mid-upload
     * on purpose, and always finishing at least one file per call so a tiny
     * budget cannot starve a run.
     *
     * @return bool true = the run reached a final state; false = more to do, queue a successor
     */
    public function work(?float $budgetSeconds = null): bool
    {
        $this->run->refresh();

        if ($this->run->isFinished()) {
            return true;
        }

        $deadline = $budgetSeconds === null ? null : microtime(true) + max(0.0, $budgetSeconds);

        try {
            if ($this->run->direction === 'size') {
                return $this->measure();
            }

            $context = $this->context();

            if ($this->run->isDownload()) {
                return (new Transfer(
                    $this->run,
                    $context['rule'],
                    $context['mount'],
                    $context['principal'],
                    $context['scope'],
                    $context['fs'],
                    $this->report,
                    $this->heartbeatEvery
                ))->work($deadline);
            }

            if ($this->run->status !== Run::UPLOADING) {
                if (!$this->scan($context)) {
                    return true;
                }
            }

            return $this->upload($context, $deadline);
        } catch (Throwable $e) {
            if (Runs::stopped($this->run)) {
                return true;
            }

            $this->fail($e->getMessage());

            return true;
        }
    }

    /**
     * The remote folder a rule writes into.
     *
     * Mount root, then — on a site mount, for a manager who cannot administer —
     * the jail `u<id>/` that keeps one manager out of another's files, then the
     * rule's own prefix. The jail sits *before* the rule prefix so no prefix can
     * climb out of it.
     *
     * @throws RuntimeException when a stored prefix is not a clean path
     */
    public static function remotePrefix(Rule $rule, Mount $mount, Principal $principal): string
    {
        $parts = [];

        foreach ([(string) $mount->option('root', ''), $mount->owner_id === null && !$principal->mayManage() ? 'u' . $principal->id : '', (string) $rule->remote_prefix] as $part) {
            $clean = Paths::clean($part);

            if ($clean === null) {
                throw new RuntimeException('The remote folder "' . $part . '" is not a valid path.');
            }

            if ($clean !== '') {
                $parts[] = $clean;
            }
        }

        return implode('/', $parts);
    }

    // ------------------------------------------------------------------
    // Context
    // ------------------------------------------------------------------

    /** @return array{rule: Rule, mount: Mount, principal: Principal, scope: Scope, fs: Filesystem} */
    private function context(): array
    {
        $rule = Rule::query()->find($this->run->rule_id);

        if ($rule === null) {
            throw new RuntimeException('The rule of this run no longer exists.');
        }

        if (!$this->run->isDownload() && !$rule->enabled) {
            throw new RuntimeException('The rule is disabled.');
        }

        $mount = Mount::query()->find($rule->mount_id);

        if ($mount === null) {
            throw new RuntimeException('The mount of this rule no longer exists.');
        }

        // Uploads run as the rule's owner; a download runs as the person who asked.
        $userId = $this->run->isDownload() && (int) $this->run->started_by > 0 ? (int) $this->run->started_by : (int) $rule->owner_id;
        $principal = Principal::forUser($userId);

        if (!$principal->active) {
            throw new RuntimeException('The account behind this run is blocked or no longer exists.');
        }

        if (!$principal->mayUse()) {
            throw new RuntimeException('The account behind this run no longer has permission to use aMount.');
        }

        if ($this->run->isDownload() && (int) $rule->owner_id !== $userId && !$principal->mayManage()) {
            throw new RuntimeException('The account behind this run no longer has access to these files.');
        }

        if (!Runs::mountUsable($mount, $principal)) {
            throw new RuntimeException('The account behind this run may no longer use this mount.');
        }

        return [
            'rule' => $rule,
            'mount' => $mount,
            'principal' => $principal,
            'scope' => Scope::for($principal),
            'fs' => $mount->filesystem(),
        ];
    }

    // ------------------------------------------------------------------
    // Measuring
    // ------------------------------------------------------------------

    /**
     * Count what a mount, a rule's remote folder or a rule's local folder holds.
     *
     * Read-only. Not sliced: a count has no state worth resuming, and the
     * budget is ignored. Progress is persisted as it goes (a snapshot in the
     * run's payload plus the counters), so the page shows live numbers; the
     * final figures are also written to the mount or rule for instant display.
     */
    private function measure(): bool
    {
        $payload = is_array($this->run->payload_json) ? $this->run->payload_json : [];
        $target = (string) ($payload['target'] ?? 'mount');
        $sub = (string) (Paths::clean((string) ($payload['path'] ?? '')) ?? '');

        if (!Runs::advance($this->run, ['status' => Run::SCANNING, 'started_at' => $this->run->started_at ?? now(), 'heartbeat_at' => now(), 'message' => null])) {
            return true;
        }

        $principal = Principal::forUser((int) $this->run->started_by);

        if (!$principal->active || !$principal->mayUse()) {
            throw new RuntimeException('The account behind this measurement may no longer use aMount.');
        }

        $mount = Mount::query()->where('uuid', (string) ($payload['mount'] ?? ''))->first();
        $rule = empty($payload['rule']) ? null : Rule::query()->where('uuid', (string) $payload['rule'])->first();

        if ($mount === null || ($target !== 'mount' && $rule === null)) {
            throw new RuntimeException('The mount or rule to measure no longer exists.');
        }

        if (!Runs::mountUsable($mount, $principal)) {
            throw new RuntimeException('The account behind this measurement may not use this mount.');
        }

        if ($rule !== null && (int) $rule->owner_id !== $principal->id && !$principal->mayManage()) {
            throw new RuntimeException('The account behind this measurement has no access to this rule.');
        }

        if ($target === 'mount' && !$mount->isPersonal() && !$principal->mayManage()) {
            throw new RuntimeException('Only an administrator can measure a whole site mount.');
        }

        $rows = [];
        $files = 0;
        $bytes = 0;
        $lastBeat = microtime(true);

        $count = function (string $label, int $size, string $current) use (&$rows, &$files, &$bytes, &$lastBeat, &$payload): bool {
            $files++;
            $bytes += $size;
            $rows[$label] ??= ['label' => $label, 'bytes' => 0, 'files' => 0, 'partial' => true];
            $rows[$label]['bytes'] += $size;
            $rows[$label]['files']++;

            if ($files % 500 !== 0 && microtime(true) - $lastBeat < $this->heartbeatEvery) {
                return true;
            }

            $lastBeat = microtime(true);
            $payload['usage'] = ['bytes' => $bytes, 'files' => $files, 'partial' => true, 'rows' => array_values($rows)];
            $this->emit('Measuring: ' . $files . ' file(s)', 'info', ['files' => $files, 'bytes' => $bytes]);

            return Runs::advance($this->run, [
                'files_done' => $files,
                'bytes_done' => $bytes,
                'current_file' => mb_substr($current, 0, 1000),
                'payload_json' => $payload,
                'heartbeat_at' => now(),
            ]);
        };

        if ($target === 'local') {
            $scope = Scope::for($principal);
            $folder = (string) Paths::clean($rule->local_path);
            $absolute = Paths::absolute($sub === '' ? $folder : $folder . '/' . $sub);

            if (is_file($absolute)) {
                $items = $scope->allowsFile($absolute) ? [['path' => $absolute, 'size' => (int) filesize($absolute)]] : [];
            } elseif (is_dir($absolute) && ($reason = $scope->denyDirectory($absolute)) === null) {
                $items = $scope->files($absolute, true);
            } else {
                throw new RuntimeException('The folder "' . $folder . '" is not visible to you.');
            }

            foreach ($items as $item) {
                $below = ltrim(substr((string) Paths::relative($item['path']), strlen($folder)), '/');
                $label = str_contains($below, '/') ? explode('/', $below, 2)[0] : '/';

                if (!$count($label, (int) $item['size'], (string) Paths::relative($item['path']))) {
                    return true;
                }
            }
        } else {
            $prefix = $target === 'rule'
                ? static::remotePrefix($rule, $mount, Principal::forUser((int) $rule->owner_id))
                : Paths::remote((string) Paths::clean((string) $mount->option('root', '')), '');
            $prefix = Paths::remote($prefix, $sub);
            $fs = $mount->filesystem();

            foreach ($fs->listContents($prefix, true) as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                $below = ltrim(substr($item->path(), strlen($prefix)), '/');
                $label = str_contains($below, '/') ? explode('/', $below, 2)[0] : '/';

                if (!$count($label, (int) $item->fileSize(), $item->path())) {
                    return true;
                }
            }
        }

        foreach ($rows as &$row) {
            $row['partial'] = false;
        }
        unset($row);

        uasort($rows, static fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

        $usage = ['bytes' => $bytes, 'files' => $files, 'measured_at' => now()->toIso8601String(), 'rows' => array_values($rows)];
        $payload['usage'] = $usage;

        // The whole mount, or a rule's whole remote folder, is what the cached
        // figure on the row stands for; a sub-folder or the local count is not.
        if ($sub === '' && $target !== 'local') {
            $model = $target === 'rule' ? $rule : $mount;
            $model::query()->whereKey($model->getKey())->update(['usage_json' => json_encode($usage)]);
        }

        $message = 'Measured ' . $files . ' file(s), ' . Paths::bytes($bytes) . '.';

        Runs::advance($this->run, [
            'status' => Run::DONE,
            'files_total' => $files,
            'files_done' => $files,
            'bytes_total' => $bytes,
            'bytes_done' => $bytes,
            'payload_json' => $payload,
            'message' => $message,
            'current_file' => null,
            'finished_at' => now(),
        ]);
        $this->emit($message);

        return true;
    }

    // ------------------------------------------------------------------
    // Scan
    // ------------------------------------------------------------------

    /**
     * Walk the rule folder once and bring the index up to date.
     *
     * @return bool false when the run was cancelled meanwhile
     */
    private function scan(array $c): bool
    {
        /** @var Rule $rule */
        $rule = $c['rule'];
        /** @var Scope $scope */
        $scope = $c['scope'];

        if (!Runs::advance($this->run, [
            'status' => Run::SCANNING,
            'started_at' => $this->run->started_at ?? now(),
            'heartbeat_at' => now(),
            'message' => null,
        ])) {
            return false;
        }

        $folder = Paths::clean($rule->local_path);

        if ($folder === null) {
            throw new RuntimeException('The rule folder is not a valid path.');
        }

        $absolute = Paths::absolute($folder);
        $single = is_file($absolute);

        if ($single) {
            $reason = $scope->denyFile($absolute) ?? $scope->denyDirectory(dirname($absolute));
            $files = $reason === null ? [['path' => $absolute, 'size' => (int) filesize($absolute), 'mtime' => (int) filemtime($absolute)]] : [];
        } else {
            $reason = is_dir($absolute) ? $scope->denyDirectory($absolute) : 'missing';
            $files = $reason === null ? $scope->files($absolute, (bool) $rule->recursive) : [];
        }

        if ($reason !== null) {
            throw new RuntimeException(
                $reason === 'missing'
                    ? 'The folder "' . $folder . '" does not exist.'
                    : 'The folder "' . $folder . '" is not visible to the rule owner (' . $reason . ').'
            );
        }

        $prefix = static::remotePrefix($rule, $c['mount'], $c['principal']);
        $include = $rule->includes();
        $exclude = $rule->excludes();
        $now = time();
        $lastBeat = microtime(true);

        foreach ($files as $file) {
            $site = Paths::relative($file['path']);

            if ($site === null || str_ends_with($site, '.amount-part')) {
                continue;
            }

            $below = $single ? basename($site) : ltrim(substr($site, strlen($folder)), '/');

            if (!Paths::matches($below, $include, $exclude)) {
                continue;
            }

            if ($now - $file['mtime'] < (int) $rule->min_age) {
                continue;
            }

            if ($file['size'] < (int) $rule->min_size || ((int) $rule->max_size > 0 && $file['size'] > (int) $rule->max_size)) {
                continue;
            }

            $this->index($rule, $site, Paths::remote($prefix, $below), $file['size'], $file['mtime']);

            if (microtime(true) - $lastBeat >= $this->heartbeatEvery) {
                $lastBeat = microtime(true);

                if (!Runs::advance($this->run, ['heartbeat_at' => now()])) {
                    return false;
                }
            }
        }

        if ($rule->mirror_deletes) {
            $this->mirrorDeletes($rule, $scope, $c['fs']);
        }

        if ((int) $rule->retention_days > 0) {
            $this->expireOld($rule, $c['fs']);
        }

        $todo = $this->candidates($rule);

        return Runs::advance($this->run, [
            'status' => Run::UPLOADING,
            'files_total' => $todo->count(),
            'bytes_total' => (int) $todo->sum('size'),
            'files_done' => 0,
            'files_failed' => 0,
            'bytes_done' => 0,
            'heartbeat_at' => now(),
        ]);
    }

    /** Insert or refresh one index row from what the scan saw. */
    private function index(Rule $rule, string $site, string $remote, int $size, int $mtime): void
    {
        $hash = Paths::hash($site);
        $entry = FileEntry::query()->where('rule_id', $rule->getKey())->where('path_hash', $hash)->first();

        if ($entry === null) {
            FileEntry::query()->create([
                'rule_id' => $rule->getKey(),
                'mount_id' => $rule->mount_id,
                'path_hash' => $hash,
                'local_path' => $site,
                'remote_path' => $remote,
                'size' => $size,
                'mtime' => $mtime,
                'visibility' => $rule->visibility,
                'status' => FileEntry::PENDING,
                'attempts' => 0,
                'seen_at' => now(),
            ]);

            return;
        }

        $changed = (int) $entry->size !== $size || (int) $entry->mtime !== $mtime || $entry->remote_path !== $remote;

        if ($changed) {
            $entry->forceFill([
                'remote_path' => $remote,
                'size' => $size,
                'mtime' => $mtime,
                'visibility' => $rule->visibility,
                'status' => FileEntry::PENDING,
                'attempts' => 0,
                'error' => null,
                'local_removed' => false,
                'seen_at' => now(),
            ])->save();

            return;
        }

        // Unchanged. An uploaded file is just re-seen; a failed or expired one
        // is left alone (an expired copy must not be re-uploaded on every scan).
        if ($entry->status === FileEntry::UPLOADED) {
            $entry->forceFill(['seen_at' => now()])->save();
        }
    }

    /**
     * Follow local deletions to the store. A file counts as vanished only when
     * it is gone from the disk, not merely absent from this scan (it may have
     * been filtered out or become invisible), and only when its owner could
     * delete it.
     */
    private function mirrorDeletes(Rule $rule, Scope $scope, Filesystem $fs): void
    {
        FileEntry::query()->where('rule_id', $rule->getKey())->where('status', FileEntry::UPLOADED)
            ->where('local_removed', false)->orderBy('id')->get()
            ->each(function (FileEntry $entry) use ($scope, $fs) {
                $absolute = Paths::absolute($entry->local_path);

                if (file_exists($absolute) || is_link($absolute) || !$scope->canModify($absolute)) {
                    return;
                }

                $this->expire($entry, $fs);
            });
    }

    /** Delete store copies older than the rule's retention. */
    private function expireOld(Rule $rule, Filesystem $fs): void
    {
        FileEntry::query()->where('rule_id', $rule->getKey())->where('status', FileEntry::UPLOADED)
            ->where('uploaded_at', '<', now()->subDays((int) $rule->retention_days))
            ->orderBy('id')->get()
            ->each(fn (FileEntry $entry) => $this->expire($entry, $fs));
    }

    private function expire(FileEntry $entry, Filesystem $fs): void
    {
        try {
            $fs->delete($entry->remote_path);
        } catch (Throwable $e) {
            $entry->forceFill(['error' => mb_substr('Could not delete the remote copy: ' . $e->getMessage(), 0, 500)])->save();

            return;
        }

        $entry->forceFill(['status' => FileEntry::EXPIRED, 'error' => null])->save();
    }

    // ------------------------------------------------------------------
    // Upload
    // ------------------------------------------------------------------

    private function upload(array $c, ?float $deadline): bool
    {
        /** @var Rule $rule */
        $rule = $c['rule'];
        $cursor = 0;
        $processed = 0;

        while (true) {
            if (Runs::stopped($this->run)) {
                return true;
            }

            if ($processed > 0 && $deadline !== null && microtime(true) >= $deadline) {
                return false;
            }

            $entry = $this->candidateQuery($rule)->where('id', '>', $cursor)->orderBy('id')->first();

            if ($entry === null) {
                break;
            }

            $cursor = (int) $entry->getKey();
            $processed++;

            if ($this->uploadOne($c, $entry) === 'cancelled') {
                return true;
            }
        }

        $this->finish($rule);

        return true;
    }

    private function candidateQuery(Rule $rule)
    {
        $max = max(1, (int) Config::limit('max_attempts', 5));

        return FileEntry::query()->where('rule_id', $rule->getKey())->where(function ($query) use ($max) {
            $query->whereIn('status', [FileEntry::PENDING, FileEntry::UPLOADING])
                ->orWhere(function ($failed) use ($max) {
                    $failed->where('status', FileEntry::FAILED)->where('attempts', '<', $max);
                });
        });
    }

    private function candidates(Rule $rule)
    {
        return $this->candidateQuery($rule)->get(['id', 'size']);
    }

    /** @return string ok | failed | skipped | cancelled */
    private function uploadOne(array $c, FileEntry $entry): string
    {
        /** @var Rule $rule */
        $rule = $c['rule'];
        /** @var Mount $mount */
        $mount = $c['mount'];
        /** @var Scope $scope */
        $scope = $c['scope'];
        /** @var Filesystem $fs */
        $fs = $c['fs'];

        $absolute = Paths::absolute($entry->local_path);
        $size = (int) $entry->size;
        $baseBytes = (int) $this->run->bytes_done;

        clearstatcache(true, $absolute);

        // Asked again, per file, as the owner stands now: the cheapest defence
        // against a restriction added between the scan and this moment.
        $reason = $scope->denyFile($absolute) ?? $scope->denyDirectory(dirname($absolute));

        if ($reason !== null) {
            return $this->giveUp($entry, 'No longer visible to the rule owner (' . $reason . ').', $baseBytes + $size);
        }

        if (!is_file($absolute)) {
            return $this->giveUp($entry, 'The file no longer exists.', $baseBytes + $size);
        }

        $size = (int) filesize($absolute);
        $mtime = (int) filemtime($absolute);

        // Still being written since the scan: leave it for the next run.
        if (($size !== (int) $entry->size || $mtime !== (int) $entry->mtime) && time() - $mtime < (int) $rule->min_age) {
            $entry->forceFill(['size' => $size, 'mtime' => $mtime])->save();

            return 'skipped';
        }

        $entry->forceFill(['status' => FileEntry::UPLOADING, 'size' => $size, 'mtime' => $mtime, 'visibility' => $rule->visibility])->save();

        $stream = @fopen($absolute, 'rb');

        if (!is_resource($stream)) {
            return $this->failEntry($entry, 'The file cannot be read.', $baseBytes + $size);
        }

        $read = 0;
        $lastBeat = microtime(true);

        ProgressStream::attach($stream, function (int $chunk) use (&$read, &$lastBeat, $baseBytes, $entry): bool {
            $read += $chunk;

            if (microtime(true) - $lastBeat < $this->heartbeatEvery) {
                return true;
            }

            $lastBeat = microtime(true);

            return $this->heartbeat($entry->local_path, $baseBytes + $read);
        });

        try {
            $driver = Drivers::get($mount->driver);
            $fs->writeStream($entry->remote_path, $stream, $driver->writeConfig($mount->options(), (string) $rule->visibility));

            $remoteSize = (int) $fs->fileSize($entry->remote_path);

            if ($remoteSize !== $size) {
                throw new RuntimeException('Size mismatch after upload: sent ' . $size . ' bytes, the store reports ' . $remoteSize . '.');
            }
        } catch (Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            // A half-written object is worse than none: it has the right name.
            try {
                $fs->delete($entry->remote_path);
            } catch (Throwable $ignored) {
            }

            if ($e instanceof TransferAborted || Runs::stopped($this->run)) {
                $entry->forceFill(['status' => FileEntry::PENDING])->save();

                return 'cancelled';
            }

            return $this->failEntry($entry, $e->getMessage(), $baseBytes + $size);
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        clearstatcache(true, $absolute);
        $unchanged = is_file($absolute) && (int) filesize($absolute) === $size && (int) filemtime($absolute) === $mtime;
        $removed = false;

        if ($rule->isMove() && $unchanged && $scope->canModify($absolute)) {
            $removed = @unlink($absolute);
        }

        $entry->forceFill([
            'status' => FileEntry::UPLOADED,
            'attempts' => 0,
            'error' => null,
            'local_removed' => $removed,
            'uploaded_at' => now(),
            'seen_at' => now(),
        ])->save();

        $this->advanceCounters(['files_done' => (int) $this->run->files_done + 1], $baseBytes + $size, $entry->local_path, 'Uploaded ' . basename($entry->local_path));

        return 'ok';
    }

    /** A file that will never be retried until it changes: invisible or gone. */
    private function giveUp(FileEntry $entry, string $message, int $bytes): string
    {
        $entry->forceFill([
            'status' => FileEntry::FAILED,
            'attempts' => max(1, (int) Config::limit('max_attempts', 5)),
            'error' => $message,
        ])->save();

        $this->advanceCounters(['files_failed' => (int) $this->run->files_failed + 1], $bytes, $entry->local_path, $message, 'warning');

        return 'failed';
    }

    private function failEntry(FileEntry $entry, string $message, int $bytes): string
    {
        $entry->forceFill([
            'status' => FileEntry::FAILED,
            'attempts' => (int) $entry->attempts + 1,
            'error' => mb_substr($message, 0, 500),
        ])->save();

        $this->advanceCounters(['files_failed' => (int) $this->run->files_failed + 1], $bytes, $entry->local_path, $message, 'warning');

        return 'failed';
    }

    private function advanceCounters(array $counters, int $bytesDone, string $file, string $message, string $level = 'info'): void
    {
        Runs::advance($this->run, $counters + ['bytes_done' => $bytesDone, 'current_file' => mb_substr($file, 0, 1000), 'heartbeat_at' => now()]);
        $this->emit($message, $level, ['file' => $file]);
    }

    /**
     * Progress tick while a file streams: tell the reporter, renew the
     * heartbeat, and learn whether the run was cancelled.
     */
    private function heartbeat(string $file, int $bytesDone): bool
    {
        $this->emit('Uploading ' . basename($file), 'info', ['file' => $file, 'bytes_done' => $bytesDone]);

        return Runs::advance($this->run, ['bytes_done' => $bytesDone, 'current_file' => mb_substr($file, 0, 1000), 'heartbeat_at' => now()]);
    }

    private function emit(string $message, string $level = 'info', array $context = []): void
    {
        if ($this->report === null) {
            return;
        }

        ($this->report)($this->run->status ?: Run::UPLOADING, $this->run->percent(), mb_substr($message, 0, 255), $level, $context + ['run_uuid' => (string) $this->run->uuid]);
    }

    // ------------------------------------------------------------------
    // End
    // ------------------------------------------------------------------

    private function finish(Rule $rule): void
    {
        $done = (int) $this->run->files_done;
        $failed = (int) $this->run->files_failed;

        if ($done === 0 && $failed === 0) {
            $message = 'Nothing to upload.';
        } else {
            $message = 'Uploaded ' . $done . ' file(s), ' . Paths::bytes((int) $this->run->bytes_done) . ($failed > 0 ? '; ' . $failed . ' failed.' : '.');
        }

        $status = $failed > 0 && $done === 0 ? Run::FAILED : Run::DONE;

        if (Runs::advance($this->run, ['status' => $status, 'message' => $message, 'current_file' => null, 'finished_at' => now(), 'heartbeat_at' => now()])) {
            Runs::schedule($rule);
        }

        $this->emit($message, $status === Run::DONE ? 'info' : 'error');
    }

    private function fail(string $message): void
    {
        $message = mb_substr($message, 0, 500);

        if (Runs::advance($this->run, ['status' => Run::FAILED, 'message' => $message, 'current_file' => null, 'finished_at' => now()])) {
            $rule = Rule::query()->find($this->run->rule_id);

            if ($rule !== null && !$this->run->isDownload()) {
                Runs::schedule($rule);
            }
        }

        $this->emit($message, 'error');
    }
}
