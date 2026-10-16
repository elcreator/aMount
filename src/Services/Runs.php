<?php

namespace Elcreator\aMount\Services;

use Carbon\Carbon;
use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Queue\Dispatcher;
use Elcreator\aMount\Support\Config;
use Elcreator\aMount\Support\Paths;
use Elcreator\aMount\Support\Principal;
use Elcreator\aMount\Support\Scope;
use RuntimeException;
use Throwable;

/**
 * The lifecycle of a run: who may start one, how it is cancelled, what happens
 * to the ones that were abandoned, and what the scheduler does every minute.
 *
 * The queue only carries a run; everything the page shows and everything a
 * cancel needs lives in `amount_runs`. That is why the progress helpers are
 * here as statics: the syncer and the transfer worker both write a run's state
 * through them, and both must lose to a cancel — every write is conditional on
 * the run still being active, so a late progress tick can never resurrect a
 * run somebody has already stopped.
 */
class Runs
{
    public function __construct()
    {
    }

    /**
     * Queue a run of a rule's own job (local to store).
     *
     * @throws RuntimeException when the rule cannot run now or the user may not start it
     */
    public function start(Rule $rule, int $userId, string $trigger = 'manual'): Run
    {
        if (!Config::isEnabled()) {
            throw new RuntimeException('aMount is disabled.');
        }

        if (!$rule->enabled) {
            throw new RuntimeException('This rule is disabled.');
        }

        $principal = Principal::forUser($userId);

        if (!$principal->mayUse()) {
            throw new RuntimeException('This account may not use aMount.');
        }

        if ((int) $rule->owner_id !== $userId && !$principal->mayManage()) {
            throw new RuntimeException('Only the rule owner or an administrator may run this rule.');
        }

        if (static::activeRunOf($rule) !== null) {
            throw new RuntimeException('This rule is already running.');
        }

        $run = Run::query()->create([
            'rule_id' => $rule->getKey(),
            'started_by' => $userId,
            'trigger' => $trigger === 'schedule' ? 'schedule' : 'manual',
            'direction' => 'up',
            'status' => Run::QUEUED,
            'heartbeat_at' => now(),
        ]);

        return $this->dispatch($run);
    }

    /**
     * Queue a download of files that were offloaded earlier.
     *
     * The destination is validated here, against what the *asking* user can
     * see, so a refusal reaches them at once instead of surfacing hours later in
     * a worker log. The worker validates again when it gets there.
     *
     * @param int[] $entryIds
     * @param array{to?: ?string, overwrite?: bool, delete_remote?: bool} $options
     *
     * @throws RuntimeException
     */
    public function recall(array $entryIds, int $userId, array $options = []): Run
    {
        if (!Config::isEnabled()) {
            throw new RuntimeException('aMount is disabled.');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $entryIds))));

        if ($ids === []) {
            throw new RuntimeException('Nothing to download.');
        }

        $principal = Principal::forUser($userId);

        if (!$principal->mayUse()) {
            throw new RuntimeException('This account may not use aMount.');
        }

        $entries = FileEntry::query()->whereIn('id', $ids)->get();

        if ($entries->count() !== count($ids)) {
            throw new RuntimeException('Some of these files are not known to aMount.');
        }

        $ruleIds = $entries->pluck('rule_id')->unique();

        if ($ruleIds->count() !== 1) {
            throw new RuntimeException('Files of different rules cannot be downloaded together.');
        }

        $rule = Rule::query()->find($ruleIds->first());
        $mount = $rule === null ? null : Mount::query()->find($rule->mount_id);

        if ($rule === null || $mount === null) {
            throw new RuntimeException('The rule or the mount of these files no longer exists.');
        }

        if ((int) $rule->owner_id !== $userId && !$principal->mayManage()) {
            throw new RuntimeException('These files belong to another manager.');
        }

        if (!static::mountUsable($mount, $principal)) {
            throw new RuntimeException('This account may not use the mount of these files.');
        }

        $busy = Run::query()->where('rule_id', $rule->getKey())->where('direction', 'down')
            ->whereIn('status', Run::ACTIVE)->exists();

        if ($busy) {
            throw new RuntimeException('A download for this rule is already running.');
        }

        $to = $options['to'] ?? null;
        $to = ($to === null || trim((string) $to) === '') ? null : (string) $to;
        $overwrite = !empty($options['overwrite']);
        $scope = Scope::for($principal);

        foreach ($entries as $entry) {
            if ($entry->status !== FileEntry::UPLOADED) {
                throw new RuntimeException('"' . basename($entry->local_path) . '" is not stored remotely.');
            }

            // Throws a plain-English reason when the destination is not allowed.
            Transfer::destination($scope, $entry, $to, $overwrite);
        }

        $run = Run::query()->create([
            'rule_id' => $rule->getKey(),
            'started_by' => $userId,
            'trigger' => 'manual',
            'direction' => 'down',
            'status' => Run::QUEUED,
            'payload_json' => [
                'entries' => $ids,
                'to' => $to,
                'overwrite' => $overwrite,
                'delete_remote' => !empty($options['delete_remote']),
                'results' => [],
            ],
            'files_total' => count($ids),
            'bytes_total' => (int) $entries->sum('size'),
            'heartbeat_at' => now(),
        ]);

        return $this->dispatch($run);
    }

    /**
     * Queue a measurement: how much does a mount, a rule's remote folder or (with
     * `$local`) a rule's local folder hold?
     *
     * Read-only by construction. A whole site mount can only be measured by an
     * administrator, because its total includes other managers' `u<id>/` folders
     * and the result is stored on the shared mount row; a manager measures their
     * own rule instead, which is bounded by their jail prefix.
     *
     * @throws RuntimeException
     */
    public function measure(Mount|Rule $target, int $userId, ?string $path = null, bool $local = false): Run
    {
        $principal = Principal::forUser($userId);

        if (!$principal->mayUse()) {
            throw new RuntimeException('This account may not use aMount.');
        }

        $sub = Paths::clean($path);

        if ($sub === null) {
            throw new RuntimeException('The folder is not a valid path.');
        }

        if ($target instanceof Rule) {
            $mount = Mount::query()->find($target->mount_id);

            if ($mount === null) {
                throw new RuntimeException('The mount of this rule no longer exists.');
            }

            if ((int) $target->owner_id !== $userId && !$principal->mayManage()) {
                throw new RuntimeException('This rule belongs to another manager.');
            }

            $rule = $target;
        } else {
            $mount = $target;
            $rule = null;

            if ($local) {
                throw new RuntimeException('A local measurement needs a rule.');
            }

            if (!$mount->isPersonal() && !$principal->mayManage()) {
                throw new RuntimeException('Only an administrator can measure a whole site mount.');
            }
        }

        if (!static::mountUsable($mount, $principal)) {
            throw new RuntimeException('This account may not use this mount.');
        }

        $payload = [
            'target' => $local ? 'local' : ($rule !== null ? 'rule' : 'mount'),
            'mount' => (string) $mount->uuid,
            'rule' => $rule?->uuid,
            'path' => $sub,
        ];

        $busy = Run::query()->where('direction', 'size')->whereIn('status', Run::ACTIVE)
            ->where('rule_id', $rule === null ? 0 : $rule->getKey())->get()
            ->contains(fn (Run $run) => ($run->payload_json['target'] ?? '') === $payload['target']
                && ($run->payload_json['mount'] ?? '') === $payload['mount'] && ($run->payload_json['path'] ?? '') === $sub);

        if ($busy) {
            throw new RuntimeException('This is already being measured.');
        }

        $run = Run::query()->create([
            'rule_id' => $rule === null ? 0 : $rule->getKey(),
            'started_by' => $userId,
            'trigger' => 'manual',
            'direction' => 'size',
            'status' => Run::QUEUED,
            'payload_json' => $payload,
            'heartbeat_at' => now(),
        ]);

        return $this->dispatch($run);
    }

    /**
     * Fail measurements that stopped reporting. A count is cheap to redo, so
     * unlike a transfer it is never queued again: the person clicks once more.
     */
    public function healSize(): int
    {
        $cutoff = Carbon::now()->subSeconds(max(60, (int) Config::limit('stale_after', 900)));
        $healed = 0;

        foreach (Run::query()->where('direction', 'size')->whereIn('status', Run::ACTIVE)->get() as $run) {
            $last = $run->heartbeat_at ?? $run->updated_at ?? $run->created_at;

            if ($last !== null && $last->greaterThan($cutoff)) {
                continue;
            }

            static::advance($run, ['status' => Run::FAILED, 'message' => 'The measurement stalled: no progress was reported.', 'current_file' => null, 'finished_at' => now()]);
            $healed++;
        }

        return $healed;
    }

    /**
     * Ask a run to stop. Cooperative: the worker notices before the next file
     * and between chunks of the current one, and abandons the partial copy.
     */
    public function cancel(Run $run): void
    {
        static::advance($run, [
            'status' => Run::CANCELLED,
            'message' => 'Cancelled.',
            'current_file' => null,
            'finished_at' => now(),
        ]);

        $rule = Rule::query()->find($run->rule_id);

        if ($rule !== null && $run->direction === 'up') {
            static::schedule($rule);
        }
    }

    /**
     * Put abandoned runs back on the queue.
     *
     * A run is abandoned when it is active but its heartbeat stopped — a killed
     * worker, a rebooted host. Queueing it again is safe because every step of a
     * run is idempotent. The heartbeat is renewed here so the next tick does not
     * queue it a second time while the first retry is still waiting its turn.
     */
    public function reapStale(): int
    {
        $limit = max(60, (int) Config::limit('stale_after', 900));
        $cutoff = Carbon::now()->subSeconds($limit);
        $reaped = 0;

        foreach (Run::query()->whereIn('status', Run::ACTIVE)->where('direction', '!=', 'size')->get() as $run) {
            $last = $run->heartbeat_at ?? $run->updated_at ?? $run->created_at;

            if ($last !== null && $last->greaterThan($cutoff)) {
                continue;
            }

            try {
                Dispatcher::dispatch($run);
            } catch (Throwable $e) {
                static::advance($run, ['status' => Run::FAILED, 'message' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);
                continue;
            }

            static::advance($run, ['heartbeat_at' => now()]);
            $reaped++;
        }

        return $reaped;
    }

    /**
     * What the scheduler runs every minute.
     *
     * @return array{started: int, skipped: int, reaped: int, healed: int, purged: int}
     */
    public function tick(): array
    {
        $started = 0;
        $skipped = 0;

        if (Config::isEnabled()) {
            $due = Rule::query()
                ->where('enabled', true)->where('ephemeral', false)->where('interval_minutes', '>', 0)
                ->where(function ($query) {
                    $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
                })
                ->orderBy('id')->get();

            foreach ($due as $rule) {
                if (static::activeRunOf($rule) !== null) {
                    continue;
                }

                try {
                    $this->start($rule, (int) $rule->owner_id, 'schedule');
                    $started++;
                } catch (Throwable $e) {
                    // The owner lost access, the mount is gone, or the queue is
                    // unavailable. Try again after the interval, not every minute.
                    static::postpone($rule);
                    $skipped++;
                }
            }
        }

        return [
            'started' => $started,
            'skipped' => $skipped,
            'reaped' => $this->reapStale(),
            'healed' => $this->healSize(),
            'purged' => $this->purge(),
        ];
    }

    /** Delete finished runs older than `limits.history_days`. */
    public function purge(): int
    {
        $days = (int) Config::limit('history_days', 60);

        if ($days <= 0) {
            return 0;
        }

        return (int) Run::query()
            ->whereNotIn('status', Run::ACTIVE)
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', Carbon::now()->subDays($days))
            ->delete();
    }

    // ------------------------------------------------------------------
    // Shared by the syncer and the transfer worker
    // ------------------------------------------------------------------

    public static function activeRunOf(Rule $rule): ?Run
    {
        // A measurement only reads; it must not stop the rule from running.
        return Run::query()->where('rule_id', $rule->getKey())->where('direction', '!=', 'size')
            ->whereIn('status', Run::ACTIVE)->orderByDesc('id')->first();
    }

    /**
     * Write run state — but only while the run is still active.
     *
     * @return bool false when the run is no longer active (cancelled, finished), in
     *              which case the in-memory model is reloaded and nothing was written
     */
    public static function advance(Run $run, array $attributes): bool
    {
        $stored = $attributes;

        if (array_key_exists('payload_json', $stored) && is_array($stored['payload_json'])) {
            $stored['payload_json'] = json_encode($stored['payload_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $stored['updated_at'] = now();

        $written = Run::query()->whereKey($run->getKey())->whereIn('status', Run::ACTIVE)->update($stored);

        if ($written === 0) {
            $run->refresh();

            return false;
        }

        $run->forceFill($attributes)->syncOriginal();

        return true;
    }

    /** Whether the run has been stopped (or otherwise ended) behind the worker's back. */
    public static function stopped(Run $run): bool
    {
        $status = Run::query()->whereKey($run->getKey())->value('status');

        return $status === null || !in_array($status, Run::ACTIVE, true);
    }

    /** Stamp the rule's last run and compute the next one: now + interval. */
    public static function schedule(Rule $rule): void
    {
        $interval = (int) $rule->interval_minutes;

        Rule::query()->whereKey($rule->getKey())->update([
            'last_run_at' => now(),
            'next_run_at' => $interval > 0 ? Carbon::now()->addMinutes($interval) : null,
            'updated_at' => now(),
        ]);
    }

    private static function postpone(Rule $rule): void
    {
        $interval = max(1, (int) $rule->interval_minutes);

        Rule::query()->whereKey($rule->getKey())->update([
            'next_run_at' => Carbon::now()->addMinutes($interval),
            'updated_at' => now(),
        ]);
    }

    /**
     * May this account use this mount at all? A personal mount: only its
     * owner. A site mount: anyone holding `amount`, narrowed to the granted
     * user groups when there are any (administrators are not narrowed).
     */
    public static function mountUsable(Mount $mount, Principal $principal): bool
    {
        if (!$principal->mayUse()) {
            return false;
        }

        if ($mount->isPersonal()) {
            return (int) $mount->owner_id === $principal->id;
        }

        $groups = $mount->grantedGroups();

        return $groups === [] || $principal->mayManage() || array_intersect($groups, $principal->userGroups()) !== [];
    }

    private function dispatch(Run $run): Run
    {
        try {
            Dispatcher::dispatch($run);
        } catch (Throwable $e) {
            static::advance($run, ['status' => Run::FAILED, 'message' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);

            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return $run->refresh();
    }
}
