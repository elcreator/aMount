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
    /** The most runs or rules one listing returns: an agent's context is not a database dump. */
    public const LIST_LIMIT = 50;

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
        $onConflict = ($options['on_conflict'] ?? 'skip') === 'newer' ? 'newer' : 'skip';

        // Into a computer's folder: its quota is known before anything moves (both sides know
        // each other's limits); the computer still enforces it per file.
        if ($mount->driver === 'ai-computer') {
            $this->checkComputerQuota($mount, $path, $folder);
        }

        try {
            $rule = $rules->createEphemeral([
                'mount' => $mount->uuid,
                'name' => 'Delivery: ' . basename($path),
                'local_path' => $path,
                'remote_prefix' => $folder,
                'mode' => $move ? Rule::MODE_MOVE : Rule::MODE_COPY,
                'visibility' => !empty($options['public']) ? Rule::PUBLIC : Rule::PRIVATE,
                'min_age' => 0,
                'on_conflict' => $onConflict,
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
     * Progress of a run this user started or owns (a measurement included).
     *
     * @return array<string, mixed>
     * @throws AmountException
     */
    public function status(string $runUuid): array
    {
        return $this->presentRun($this->findRun($runUuid));
    }

    /**
     * The newest runs the actor may see, newest first, at most {@see self::LIST_LIMIT}.
     *
     * @return array{runs:array<int, array<string, mixed>>}
     */
    public function runs(bool $activeOnly = false, ?string $ruleUuid = null): array
    {
        $this->actor->authorize();

        $query = Run::query()->orderByDesc('id');

        if ($activeOnly) {
            $query->whereIn('status', Run::ACTIVE);
        }

        if ($ruleUuid !== null && trim($ruleUuid) !== '') {
            $query->where('rule_id', $this->findRule($ruleUuid)->getKey());
        }

        $out = [];

        // Visibility is decided per run (see runVisible), so over-read a little and cut.
        foreach ($query->limit(self::LIST_LIMIT * 4)->get() as $run) {
            if ($this->runVisible($run)) {
                $out[] = $this->presentRun($run);
            }

            if (count($out) >= self::LIST_LIMIT) {
                break;
            }
        }

        return ['runs' => $out];
    }

    /**
     * Ask a run to stop (cooperative). Cancelling a finished run changes nothing.
     *
     * @return array<string, mixed>
     * @throws AmountException
     */
    public function cancel(string $runUuid): array
    {
        $run = $this->findRun($runUuid);

        if ($run->isActive()) {
            (new Runs())->cancel($run);
            $run = $run->fresh() ?? $run;
        }

        return $this->presentRun($run);
    }

    /**
     * The folder rules the actor owns (all of them for an administrator), minus
     * those on somebody else's personal mount. Compact: no patterns, no owners.
     *
     * @return array{rules:array<int, array<string, mixed>>}
     */
    public function rules(): array
    {
        $this->actor->authorize();

        $rules = new Rules($this->actor);
        $mounts = new Mounts($this->actor);
        $out = [];

        foreach ($rules->list() as $rule) {
            if ($rule->mount === null || !$mounts->canUse($rule->mount)) {
                continue;
            }

            $out[] = array_intersect_key($rules->present($rule), array_flip([
                'uuid', 'name', 'mount', 'mount_name', 'local_path', 'remote_prefix', 'mode', 'visibility',
                'interval_minutes', 'enabled', 'last_run_at', 'next_run_at', 'index', 'last_run',
            ]));

            if (count($out) >= self::LIST_LIMIT * 2) {
                break;
            }
        }

        return ['rules' => $out];
    }

    /**
     * One page (50) of the files the index knows for a rule, i.e. what was
     * offloaded and can be recalled. Only files the actor can still see in the
     * file manager are listed.
     *
     * @param array{status?:string, q?:string} $filters
     * @return array{data:array<int, array<string, mixed>>, page:int, per_page:int, total:int, pages:int}
     * @throws AmountException
     */
    public function files(string $ruleUuid, array $filters = [], int $page = 1): array
    {
        $this->actor->authorize();

        return (new Files($this->actor))->entries($this->findRule($ruleUuid), $filters, $page);
    }

    /**
     * Bring already offloaded files (ids from {@see files()}) back into a site
     * folder, in the background. Every check of the page applies: ownership,
     * mount access, visible destination, no executables, no overwrite unless asked.
     *
     * @param int[] $entryIds
     * @return array{run:string, files:int, to:?string}
     * @throws AmountException
     */
    public function recall(array $entryIds, ?string $to = null, bool $overwrite = false, bool $deleteRemote = false): array
    {
        $this->actor->authorize();

        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $entryIds)))), 0, 200);

        if ($ids === []) {
            throw AmountException::fields(['files' => AmountException::text('aMount::global.error_field_required')]);
        }

        // Entries of a rule the actor cannot see are "unknown", never "someone else's".
        $files = new Files($this->actor);

        foreach ($ids as $id) {
            $files->find($id);
        }

        try {
            $run = (new Runs())->recall($ids, $this->actor->userId(), [
                'to' => $to !== null && trim($to) !== '' ? (string) Paths::clean($to) : null,
                'overwrite' => $overwrite,
                'delete_remote' => $deleteRemote,
            ]);
        } catch (RuntimeException $e) {
            throw new AmountException('run_refused', $e->getMessage(), 409);
        }

        return ['run' => $run->uuid, 'files' => count($ids), 'to' => $to];
    }

    /**
     * Delete one offloaded object on the store (the local file is not touched).
     * Irreversible, so the caller must pass `confirm` explicitly.
     *
     * @return array{deleted:bool, id:int}
     * @throws AmountException
     */
    public function deleteRemote(int $entryId, bool $confirm): array
    {
        $this->actor->authorize();

        if (!$confirm) {
            throw AmountException::invalid('error_confirm_required');
        }

        $files = new Files($this->actor);
        $files->delete($files->find($entryId));

        return ['deleted' => true, 'id' => $entryId];
    }

    /**
     * Start counting what a mount (or a rule's remote or local folder) holds.
     * Poll {@see status()}: the finished run carries `usage`.
     *
     * @return array{run:string}
     * @throws AmountException
     */
    public function measure(?string $mountHint, ?string $ruleUuid = null, ?string $path = null, bool $local = false): array
    {
        $this->actor->authorize();

        $hasRule = $ruleUuid !== null && trim($ruleUuid) !== '';
        $target = $hasRule ? $this->findRule((string) $ruleUuid) : $this->mountFor($mountHint);

        try {
            $run = (new Runs())->measure($target, $this->actor->userId(), $path, $hasRule && $local);
        } catch (RuntimeException $e) {
            throw new AmountException('run_refused', $e->getMessage(), 409);
        }

        return ['run' => $run->uuid];
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
        $recursive = !empty($options['recursive']);
        $limit = $recursive ? 2000 : 200;
        $found = [];

        try {
            $fs = $mount->filesystem();

            if ($fs->fileExists($key)) {
                $found[] = ['key' => $key, 'rel' => basename($key)];
            } else {
                foreach ($fs->listContents($key, $recursive) as $item) {
                    if (!$item->isFile()) {
                        continue;
                    }
                    if (count($found) >= $limit) {
                        throw new AmountException('too_many_files', AmountException::text('aMount::global.error_fetch_too_many', ['max' => $limit]), 422);
                    }
                    // Kept relative to the folder asked for: a recursive fetch rebuilds its subfolders.
                    $rel = ltrim(substr($item->path(), strlen(rtrim($key, '/'))), '/');
                    $found[] = ['key' => $item->path(), 'rel' => $recursive ? $rel : basename($item->path())];
                }
            }
        } catch (AmountException $e) {
            throw $e;
        } catch (Throwable $e) {
            // The store's own reason (a computer's refusal, say) is under Flysystem's wrapper.
            $reason = $e->getPrevious() !== null ? $e->getMessage() . ': ' . $e->getPrevious()->getMessage() : $e->getMessage();

            throw new AmountException('fetch_failed', Mounts::redact($reason, $mount), 502);
        }

        if ($found === []) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_remote_file'));
        }

        // Files from a computer always fill a site folder only up to its quota. Checked for the
        // whole batch before anything moves, so a fetch that cannot fit says so at once.
        $quota = $mount->driver === 'ai-computer' ? \Elcreator\aMount\Drivers\ComputerDriver::siteQuota($mount->credentials()) : null;
        $sizes = [];

        try {
            foreach ($found as $i => $file) {
                $sizes[$i] = [(int) $fs->fileSize($file['key']), (int) $fs->lastModified($file['key'])];
            }
        } catch (Throwable $e) {
            throw new AmountException('fetch_failed', Mounts::redact($e->getMessage(), $mount), 502);
        }

        if ($quota !== null) {
            $used = Transfer::folderBytes(Paths::absolute($to));
            $incoming = array_sum(array_column($sizes, 0));
            if ($used + $incoming > $quota) {
                throw new AmountException('quota_exceeded', AmountException::text('aMount::global.error_site_quota', [
                    'folder' => $to, 'quota' => round($quota / 1048576), 'used' => round($used / 1048576), 'incoming' => round($incoming / 1048576),
                ]), 409);
            }
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

            foreach ($found as $i => $file) {
                $local = Paths::remote($to, $file['rel']);
                [$size, $mtime] = $sizes[$i];

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

            $onConflict = $options['on_conflict'] ?? null;
            $run = (new Runs())->recall($ids, $this->actor->userId(), [
                'to' => $to,
                'overwrite' => !empty($options['overwrite']),
                'on_conflict' => in_array($onConflict, ['skip', 'newer'], true) ? $onConflict : null,
                'quota' => $quota,
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

    /** @throws AmountException a rule the actor may not use, or whose mount is not theirs, is "not found" */
    private function findRule(string $uuid): Rule
    {
        $rule = (new Rules($this->actor))->find($uuid);

        if ($rule->mount === null || !(new Mounts($this->actor))->canUse($rule->mount)) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_rule'));
        }

        return $rule;
    }

    /** @throws AmountException */
    private function findRun(string $uuid): Run
    {
        $this->actor->authorize();

        $run = Run::query()->where('uuid', trim($uuid))->first();

        if ($run === null || !$this->runVisible($run)) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_run'));
        }

        return $run;
    }

    /**
     * The page's rule (started it, owns the rule, or administers) plus one
     * more: a run on somebody else's personal mount is invisible even to an
     * administrator, because its file names and messages come from that store.
     */
    private function runVisible(Run $run): bool
    {
        if (!$this->actor->mayUse()) {
            return false;
        }

        $rule = $run->rule_id > 0 ? Rule::query()->with('mount')->find($run->rule_id) : null;
        $payload = is_array($run->payload_json) ? $run->payload_json : [];
        $mount = $rule?->mount;

        if ($mount === null && !empty($payload['mount'])) {
            $mount = Mount::query()->where('uuid', (string) $payload['mount'])->first();
        }

        if ($mount !== null && $mount->isPersonal() && (int) $mount->owner_id !== $this->actor->userId()) {
            return false;
        }

        return $this->actor->mayManage()
            || (int) $run->started_by === $this->actor->userId()
            || ($rule !== null && (int) $rule->owner_id === $this->actor->userId());
    }

    /** @return array<string, mixed> */
    private function presentRun(Run $run): array
    {
        $payload = is_array($run->payload_json) ? $run->payload_json : [];

        return array_filter([
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
            // A finished measurement: the breakdown the question was about.
            'usage' => $run->direction === 'size' ? ($payload['usage'] ?? null) : null,
        ], static fn ($v) => $v !== null);
    }

    private function mountFor(?string $hint): Mount
    {
        $found = (new Mounts($this->actor))->resolve($hint);

        if ($found['mount'] !== null) {
            return $found['mount'];
        }

        // "From my computer mypc": a computer of the account needs no prepared mount.
        if ($hint !== null && ($computer = (new Computers($this->actor))->mountFor($hint)) !== null) {
            return $computer;
        }

        if ($found['candidates'] === []) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_mount'));
        }

        $mounts = new Mounts($this->actor);

        throw AmountException::ambiguous(array_map(static fn (Mount $m) => $mounts->summary($m), $found['candidates']));
    }

    /** The account's computers, their folders and this site's code (`amount.computers`). @return array<string, mixed> */
    public function computers(): array
    {
        return (new Computers($this->actor))->overview();
    }

    /** @throws AmountException when the files would not fit the computer folder's quota */
    private function checkComputerQuota(Mount $mount, string $path, string $folder): void
    {
        $absolute = Paths::absolute($path);
        $bytes = is_file($absolute) ? (int) filesize($absolute) : Transfer::folderBytes($absolute);
        $config = $mount->credentials();
        $share = trim((string) ($config['share'] ?? ''), '/') ?: explode('/', trim($folder, '/'))[0];

        try {
            $adapter = null;
            foreach ((new Computers($this->actor))->overview()['computers'] as $c) {
                if ((string) $c['id'] === (string) ($config['computer'] ?? '') || mb_strtolower((string) $c['name']) === mb_strtolower((string) ($config['computer'] ?? ''))) {
                    foreach ((array) $c['shares'] as $s) {
                        if (strcasecmp((string) $s['name'], $share) === 0 && (int) ($s['quota'] ?? 0) > 0 && (int) ($s['used'] ?? -1) >= 0
                            && (int) $s['used'] + $bytes > (int) $s['quota']) {
                            throw new AmountException('quota_exceeded', AmountException::text('aMount::global.error_computer_quota', [
                                'share' => $share, 'quota' => round((int) $s['quota'] / 1048576), 'used' => round((int) $s['used'] / 1048576),
                                'incoming' => round($bytes / 1048576),
                            ]), 409);
                        }
                    }
                }
            }
        } catch (AmountException $e) {
            throw $e;
        } catch (Throwable) {
            // Unknown here: the computer still refuses per file, with its reason.
        }
    }

    private function discard(Rule $rule): void
    {
        FileEntry::query()->where('rule_id', $rule->getKey())->delete();
        Run::query()->where('rule_id', $rule->getKey())->delete();
        $rule->delete();
    }
}
