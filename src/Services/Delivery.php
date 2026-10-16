<?php

namespace Elcreator\aMount\Services;

use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Support\Paths;
use RuntimeException;
use Throwable;

/**
 * The public API other packages and AI agents use: "put this file on my Google
 * Drive", "bring that backup back".
 *
 *     Delivery::for($userId)->deliver('assets/aimage/2026/cat.png', 'google drive', 'Pictures');
 *
 * It is a thin, safe front for the same machinery the manager page uses: the
 * acting user must be allowed to use the module, the mount must be one of
 * theirs, and the file must be one they can see in the file manager — otherwise
 * the call is refused exactly as a click would be. An unclear destination is
 * never guessed: {@see AmountException::ambiguous()} lists the candidates so
 * the caller can ask "which one?".
 */
final class Delivery
{
    private function __construct(private readonly Actor $actor)
    {
    }

    public static function for(int $userId): self
    {
        return new self(Actor::for($userId));
    }

    /** What this user can deliver to. @return array<int, array<string, mixed>> */
    public function mounts(): array
    {
        $mounts = new Mounts($this->actor);

        return $mounts->visible()->map(fn (Mount $m) => $mounts->summary($m))->values()->all();
    }

    /**
     * @return array<string, mixed> the mount a hint points at
     * @throws AmountException unknown (404) or ambiguous (409, with candidates)
     */
    public function resolveMount(?string $hint): array
    {
        $this->actor->authorize();

        return (new Mounts($this->actor))->summary($this->mountFor($hint));
    }

    /** @return array{path:string, folders:array<int, array{name:string, path:string}>} */
    public function browse(string $mountUuid, string $path = ''): array
    {
        $mounts = new Mounts($this->actor);

        return $mounts->browse($mounts->find($mountUuid), $path);
    }

    /**
     * Send a file or folder to a mount, in the background.
     *
     * @param array{mode?:string, move?:bool, public?:bool, origin?:string} $options
     * @return array{run:string, mount:string, mount_name:string, remote:string}
     * @throws AmountException
     */
    public function deliver(string $localSitePath, ?string $mountHint = null, ?string $remoteFolder = null, array $options = []): array
    {
        $this->actor->authorize();

        $mount = $this->mountFor($mountHint);
        $path = Paths::clean($localSitePath);

        if ($path === null || $path === '') {
            throw AmountException::invalid('error_path_invalid');
        }

        $folder = Paths::clean((string) $remoteFolder);

        if ($folder === null) {
            throw AmountException::invalid('error_path_invalid');
        }

        $move = !empty($options['move']) || ($options['mode'] ?? '') === Rule::MODE_MOVE;
        $origin = (string) ($options['origin'] ?? 'api');
        $rules = new Rules($this->actor);

        try {
            $rule = $rules->createEphemeral([
                'mount' => $mount->uuid,
                'name' => 'Delivery: ' . basename($path),
                'local_path' => $path,
                'remote_prefix' => $folder,
                'mode' => $move ? Rule::MODE_MOVE : Rule::MODE_COPY,
                'visibility' => !empty($options['public']) ? Rule::PUBLIC : Rule::PRIVATE,
                'min_age' => 0,
            ], $origin);
        } catch (AmountException $e) {
            // The page says why a folder was refused; here the cause is the file.
            if (isset($e->errors['local_path'])) {
                throw new AmountException('path_denied', $e->errors['local_path'], 403);
            }

            if (isset($e->errors['mode'])) {
                throw new AmountException('modify_denied', $e->errors['mode'], 403);
            }

            throw $e;
        }

        try {
            $run = (new Runs())->start($rule, $this->actor->userId(), 'manual');
        } catch (RuntimeException $e) {
            $this->discard($rule);

            throw new AmountException('run_refused', $e->getMessage(), 409);
        }

        $remote = Syncer::remotePrefix($rule, $mount, $this->actor->principal());

        return ['run' => $run->uuid, 'mount' => $mount->uuid, 'mount_name' => $mount->name, 'remote' => $remote];
    }

    /**
     * Progress of a run this user started or owns.
     *
     * @return array<string, mixed>
     * @throws AmountException
     */
    public function status(string $runUuid): array
    {
        $this->actor->authorize();

        $run = Run::query()->where('uuid', trim($runUuid))->first();
        $rule = $run === null ? null : Rule::query()->find($run->rule_id);

        if ($run === null || $rule === null || ((int) $rule->owner_id !== $this->actor->userId() && !$this->actor->mayManage())) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_run'));
        }

        return [
            'run' => $run->uuid,
            'status' => $run->status,
            'direction' => $run->direction,
            'finished' => $run->isFinished(),
            'percent' => $run->percent(),
            'files_total' => (int) $run->files_total,
            'files_done' => (int) $run->files_done,
            'files_failed' => (int) $run->files_failed,
            'bytes_total' => (int) $run->bytes_total,
            'bytes_done' => (int) $run->bytes_done,
            'current_file' => $run->current_file,
            'message' => $run->message,
        ];
    }

    /**
     * Download a file (or the files directly inside a folder) from a mount back
     * into a site folder, in the background. Works for objects aMount never
     * uploaded too: they are registered on the fly, then restored through the
     * ordinary download run with all its checks (visible folder, no executables,
     * no overwrite unless asked).
     *
     * @param array{overwrite?:bool, delete_remote?:bool, origin?:string} $options
     * @return array{run:string, mount:string, files:int, to:string}
     * @throws AmountException
     */
    public function fetch(string $mountHint, string $remotePath, string $toFolder, array $options = []): array
    {
        $this->actor->authorize();

        $mounts = new Mounts($this->actor);
        $mount = $this->mountFor($mountHint);
        $remote = Paths::clean($remotePath);
        $to = Paths::clean($toFolder);

        if ($remote === null || $remote === '' || $to === null || $to === '') {
            throw AmountException::invalid('error_path_invalid');
        }

        $base = $mounts->base($mount);
        $key = Paths::remote($base, $remote);
        $found = [];

        try {
            $fs = $mount->filesystem();

            if ($fs->fileExists($key)) {
                $found[] = ['key' => $key, 'name' => basename($key)];
            } else {
                foreach ($fs->listContents($key, false) as $item) {
                    if ($item->isFile() && count($found) < 200) {
                        $found[] = ['key' => $item->path(), 'name' => basename($item->path())];
                    }
                }
            }
        } catch (Throwable $e) {
            throw new AmountException('fetch_failed', Mounts::redact($e->getMessage(), $mount), 502);
        }

        if ($found === []) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_remote_file'));
        }

        // A throw-away rule anchors the entries to the destination folder and its checks.
        $rule = (new Rules($this->actor))->createEphemeral([
            'mount' => $mount->uuid,
            'name' => 'Fetch: ' . basename($remote),
            'local_path' => $to,
            'min_age' => 0,
        ], (string) ($options['origin'] ?? 'api-fetch'));

        try {
            $ids = [];

            foreach ($found as $file) {
                $local = Paths::remote($to, $file['name']);
                $size = (int) $fs->fileSize($file['key']);
                $mtime = (int) $fs->lastModified($file['key']);

                $ids[] = FileEntry::query()->create([
                    'rule_id' => $rule->getKey(),
                    'mount_id' => $mount->getKey(),
                    'path_hash' => Paths::hash($local),
                    'local_path' => $local,
                    'remote_path' => $file['key'],
                    'size' => $size,
                    'mtime' => $mtime,
                    'visibility' => Rule::PRIVATE,
                    'status' => FileEntry::UPLOADED,
                    'local_removed' => true,
                    'uploaded_at' => \Illuminate\Support\Carbon::now(),
                ])->getKey();
            }

            $run = (new Runs())->recall($ids, $this->actor->userId(), [
                'to' => $to,
                'overwrite' => !empty($options['overwrite']),
                'delete_remote' => !empty($options['delete_remote']),
            ]);
        } catch (RuntimeException $e) {
            $this->discard($rule);

            throw new AmountException('run_refused', $e->getMessage(), 409);
        } catch (Throwable $e) {
            $this->discard($rule);

            throw new AmountException('fetch_failed', Mounts::redact($e->getMessage(), $mount), 502);
        }

        return ['run' => $run->uuid, 'mount' => $mount->uuid, 'files' => count($ids), 'to' => $to];
    }

    private function mountFor(?string $hint): Mount
    {
        $found = (new Mounts($this->actor))->resolve($hint);

        if ($found['mount'] !== null) {
            return $found['mount'];
        }

        if ($found['candidates'] === []) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_mount'));
        }

        $mounts = new Mounts($this->actor);

        throw AmountException::ambiguous(array_map(static fn (Mount $m) => $mounts->summary($m), $found['candidates']));
    }

    private function discard(Rule $rule): void
    {
        FileEntry::query()->where('rule_id', $rule->getKey())->delete();
        Run::query()->where('rule_id', $rule->getKey())->delete();
        $rule->delete();
    }
}
