<?php

namespace Elcreator\aMount\Services;

use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Support\Paths;
use Elcreator\aMount\Support\Scope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Folder rules: "send this folder to that mount".
 *
 * Everything a rule may name is checked against what the *owner* can see in the
 * file manager ({@see Scope}), and — when somebody else saves it for them —
 * against what the saving manager can see too. The same checks run again on
 * every run, per file; this class only keeps an impossible rule from being
 * saved in the first place.
 */
final class Rules
{
    public const MIN_INTERVAL = 5;

    /** Top-level folders a web server never serves, so they can never be made public. */
    private const NEVER_PUBLIC = [
        'core', 'manager', 'vendor', 'views', 'tmp', 'temp', 'install', 'storage',
        'assets/cache', 'assets/backup', 'assets/export', 'assets/import',
    ];

    public function __construct(private readonly Actor $actor)
    {
    }

    /** @return Collection<int, Rule> */
    public function list(bool $ephemeral = false): Collection
    {
        if (!$this->actor->mayUse()) {
            return new Collection();
        }

        $query = Rule::query()->with('mount')->where('ephemeral', $ephemeral)->orderBy('name');

        if (!$this->actor->mayManage()) {
            $query->where('owner_id', $this->actor->userId());
        }

        return $query->get();
    }

    /** @throws AmountException */
    public function find(string $uuid): Rule
    {
        $this->actor->authorize();

        $rule = Rule::query()->with('mount')->where('uuid', trim($uuid))->first();

        if ($rule === null || !$this->canAccess($rule)) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_rule'));
        }

        return $rule;
    }

    public function canAccess(Rule $rule): bool
    {
        return $this->actor->mayUse()
            && ((int) $rule->owner_id === $this->actor->userId() || $this->actor->mayManage());
    }

    /** @throws AmountException */
    public function create(array $in): Rule
    {
        $data = $this->validate($in);
        $rule = Rule::query()->create($data + ['ephemeral' => false, 'origin' => 'ui']);
        $this->schedule($rule);

        if ($rule->isPublic()) {
            ServerConfig::refresh();
        }

        return $rule;
    }

    /**
     * A one-off delivery (Delivery API): runs once, never scheduled, listed as activity.
     *
     * @throws AmountException
     */
    public function createEphemeral(array $in, string $origin = 'api'): Rule
    {
        $in['interval_minutes'] = 0;
        $in['enabled'] = true;
        $data = $this->validate($in);

        $rule = Rule::query()->create($data + ['ephemeral' => true, 'origin' => mb_substr($origin, 0, 32), 'next_run_at' => null]);

        if ($rule->isPublic()) {
            ServerConfig::refresh();
        }

        return $rule;
    }

    /** @throws AmountException */
    public function update(Rule $rule, array $in): Rule
    {
        if (!$this->canAccess($rule)) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_rule'));
        }

        $wasPublic = $rule->isPublic();
        $rule->fill($this->validate($in, $rule));
        $rule->save();
        $this->schedule($rule);

        if ($wasPublic || $rule->isPublic()) {
            ServerConfig::refresh();
        }

        return $rule;
    }

    /** @throws AmountException */
    public function delete(Rule $rule): void
    {
        if (!$this->canAccess($rule)) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_rule'));
        }

        if (Run::query()->where('rule_id', $rule->getKey())->whereIn('status', Run::ACTIVE)->exists()) {
            throw AmountException::invalid('error_rule_running');
        }

        $wasPublic = $rule->isPublic();

        DB::transaction(static function () use ($rule) {
            Run::query()->where('rule_id', $rule->getKey())->delete();
            FileEntry::query()->where('rule_id', $rule->getKey())->delete();
            $rule->delete();
        });

        if ($wasPublic) {
            ServerConfig::refresh();
        }
    }

    /**
     * Check a rule's input and return the attributes to store.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     * @throws AmountException with `errors` (field => message) when anything is off
     */
    public function validate(array $in, ?Rule $existing = null): array
    {
        $this->actor->authorize();

        $get = static fn (string $key, $default = null) => array_key_exists($key, $in)
            ? $in[$key]
            : ($existing?->getAttribute($key) ?? $default);

        $errors = [];

        // Owner: the actor, unless an administrator names somebody else.
        $ownerId = (int) ($in['owner_id'] ?? $existing?->owner_id ?? $this->actor->userId());
        $ownerActor = $ownerId === $this->actor->userId() ? $this->actor : Actor::for($ownerId);

        if ($ownerId !== $this->actor->userId()) {
            if (!$this->actor->mayManage()) {
                throw AmountException::forbidden();
            }

            if (!$ownerActor->mayUse()) {
                $errors['owner_id'] = AmountException::text('aMount::global.error_owner_invalid');
            }
        }

        // Mount: usable by the owner, and visible to the person saving.
        $mount = null;
        $mountKey = (string) ($in['mount'] ?? $in['mount_uuid'] ?? ($existing ? $existing->mount?->uuid : ''));

        try {
            $mount = (new Mounts($this->actor))->find($mountKey);

            if ($ownerId !== $this->actor->userId() && !(new Mounts($ownerActor))->canUse($mount)) {
                throw AmountException::notFound('mount');
            }
        } catch (AmountException) {
            $errors['mount'] = AmountException::text('aMount::global.error_mount_unusable');
        }

        // Folder or file.
        $path = Paths::clean((string) $get('local_path', ''));
        $absolute = null;
        $isFile = false;

        if ($path === null || $path === '') {
            $errors['local_path'] = AmountException::text('aMount::global.error_path_invalid');
        } else {
            $absolute = Paths::absolute($path);
            $isFile = is_file($absolute);

            if (!is_dir($absolute) && !$isFile) {
                $errors['local_path'] = AmountException::text('aMount::global.error_path_missing');
            } else {
                foreach ([$this->actor, $ownerActor] as $who) {
                    if ($reason = $this->denied($who->scope(), $absolute, $isFile)) {
                        $errors['local_path'] = AmountException::text('aMount::global.error_path_denied', ['reason' => $reason]);
                        break;
                    }
                }
            }
        }

        $mode = (string) $get('mode', Rule::MODE_COPY);
        $mirror = filter_var($get('mirror_deletes', false), FILTER_VALIDATE_BOOLEAN);

        if (!in_array($mode, [Rule::MODE_COPY, Rule::MODE_MOVE], true)) {
            $errors['mode'] = AmountException::text('aMount::global.error_mode');
        }

        // Deleting local files (move) or mirroring deletions asks for more than seeing.
        if ($absolute !== null && !isset($errors['local_path']) && ($mode === Rule::MODE_MOVE || $mirror)) {
            foreach ([$this->actor, $ownerActor] as $who) {
                if (!$who->scope()->canModify($absolute)) {
                    $errors['mode'] = AmountException::text('aMount::global.error_modify_denied');
                    break;
                }
            }
        }

        $visibility = (string) $get('visibility', Rule::PRIVATE);

        if (!in_array($visibility, [Rule::PRIVATE, Rule::PUBLIC], true)) {
            $errors['visibility'] = AmountException::text('aMount::global.error_visibility');
        } elseif ($visibility === Rule::PUBLIC && $path !== null && $path !== '' && !self::publishable($path)) {
            $errors['visibility'] = AmountException::text('aMount::global.error_public_path');
        }

        $prefix = Paths::clean((string) $get('remote_prefix', ''));

        if ($prefix === null) {
            $errors['remote_prefix'] = AmountException::text('aMount::global.error_path_invalid');
        }

        $interval = (int) $get('interval_minutes', 0);

        if ($interval < 0 || ($interval > 0 && $interval < self::MIN_INTERVAL)) {
            $errors['interval_minutes'] = AmountException::text('aMount::global.error_interval', ['min' => self::MIN_INTERVAL]);
        }

        $retention = (int) $get('retention_days', 0);

        if ($retention < 0 || $retention > 36500) {
            $errors['retention_days'] = AmountException::text('aMount::global.error_number');
        }

        $minAge = (int) $get('min_age', 60);
        $minSize = (int) $get('min_size', 0);
        $maxSize = (int) $get('max_size', 0);

        if ($minAge < 0) {
            $errors['min_age'] = AmountException::text('aMount::global.error_number');
        }

        if ($minSize < 0 || $maxSize < 0 || ($maxSize > 0 && $minSize > $maxSize)) {
            $errors['max_size'] = AmountException::text('aMount::global.error_size_range');
        }

        // When the name is taken at the other end: keep what is there, or let a newer file
        // replace an older one (never an older one a newer). Destructive, so explicit.
        $onConflict = (string) ($get('on_conflict', 'skip') ?: 'skip');

        if (!in_array($onConflict, ['skip', 'newer'], true)) {
            $errors['on_conflict'] = AmountException::text('aMount::global.error_on_conflict');
        }

        $include = self::patterns($get('include', $existing?->include_json ?? []));
        $exclude = self::patterns($get('exclude', $existing?->exclude_json ?? []));

        if ($include === null || $exclude === null) {
            $errors['include'] = AmountException::text('aMount::global.error_patterns');
        }

        if ($errors !== []) {
            throw AmountException::fields($errors);
        }

        $name = trim((string) $get('name', ''));

        if ($name === '') {
            $name = basename($path) . ' → ' . $mount->name;
        }

        return [
            'mount_id' => $mount->getKey(),
            'owner_id' => $ownerId,
            'name' => mb_substr($name, 0, 120),
            'local_path' => $path,
            'remote_prefix' => (string) $prefix,
            'recursive' => filter_var($get('recursive', true), FILTER_VALIDATE_BOOLEAN),
            'mode' => $mode,
            'visibility' => $visibility,
            'mirror_deletes' => $mirror,
            'retention_days' => $retention,
            'include_json' => json_encode($include),
            'exclude_json' => json_encode($exclude),
            'min_age' => $minAge,
            'min_size' => $minSize,
            'max_size' => $maxSize,
            'interval_minutes' => $interval,
            'enabled' => filter_var($get('enabled', true), FILTER_VALIDATE_BOOLEAN),
            'on_conflict' => $onConflict,
        ];
    }

    /**
     * Ready-made suggestions, limited to what the actor can see.
     *
     * @return array<int, array<string, mixed>>
     */
    public function presets(): array
    {
        if (!$this->actor->mayUse()) {
            return [];
        }

        $principal = $this->actor->principal();
        $candidates = [];

        if ($principal->can('bk_manager')) {
            $snapshot = self::snapshotFolder();

            if ($snapshot !== null) {
                $candidates[] = [
                    'key' => 'backups', 'name' => AmountException::text('aMount::global.preset_backups'), 'local_path' => $snapshot,
                    'mode' => 'copy', 'visibility' => 'private', 'interval_minutes' => 1440,
                    'include' => ['*.sql', '*.sql.gz', '*.zip'],
                ];
            }
        }

        foreach (['images' => 'assets/images', 'files' => 'assets/files', 'media' => 'assets/media'] as $key => $folder) {
            $candidates[] = [
                'key' => $key, 'name' => AmountException::text('aMount::global.preset_' . $key), 'local_path' => $folder,
                'mode' => 'copy', 'visibility' => 'public', 'interval_minutes' => 0, 'include' => [],
            ];
        }

        $scope = $this->actor->scope();

        return array_values(array_filter($candidates, static function (array $p) use ($scope) {
            $absolute = Paths::absolute($p['local_path']);

            return is_dir($absolute) && $scope->denyDirectory($absolute) === null;
        }));
    }

    public function present(Rule $rule): array
    {
        $last = Run::query()->where('rule_id', $rule->getKey())->latest('id')->first();

        return [
            'uuid' => $rule->uuid,
            'name' => $rule->name,
            'mount' => $rule->mount?->uuid,
            'mount_name' => $rule->mount?->name,
            'owner_id' => (int) $rule->owner_id,
            'local_path' => $rule->local_path,
            'remote_prefix' => $rule->remote_prefix,
            'recursive' => (bool) $rule->recursive,
            'mode' => $rule->mode,
            'visibility' => $rule->visibility,
            'mirror_deletes' => (bool) $rule->mirror_deletes,
            'retention_days' => (int) $rule->retention_days,
            'include' => $rule->includes(),
            'exclude' => $rule->excludes(),
            'min_age' => (int) $rule->min_age,
            'min_size' => (int) $rule->min_size,
            'max_size' => (int) $rule->max_size,
            'interval_minutes' => (int) $rule->interval_minutes,
            'enabled' => (bool) $rule->enabled,
            'ephemeral' => (bool) $rule->ephemeral,
            'origin' => $rule->origin,
            'last_run_at' => $rule->last_run_at?->toIso8601String(),
            'next_run_at' => $rule->next_run_at?->toIso8601String(),
            'usage' => $rule->usage_json,
            'index' => (new Files($this->actor))->indexTotals($rule),
            'last_run' => $last ? ['uuid' => $last->uuid, 'status' => $last->status, 'message' => $last->message] : null,
        ];
    }

    /** Whether a site path may carry public visibility at all. */
    public static function publishable(string $path): bool
    {
        $lower = strtolower($path);

        foreach (self::NEVER_PUBLIC as $denied) {
            if ($lower === $denied || str_starts_with($lower, $denied . '/')) {
                return false;
            }
        }

        foreach (explode('/', $lower) as $segment) {
            if (str_starts_with($segment, '.')) {
                return false;
            }
        }

        return true;
    }

    private function denied(Scope $scope, string $absolute, bool $isFile): ?string
    {
        if (!$isFile) {
            return $scope->denyDirectory($absolute);
        }

        return $scope->denyDirectory(dirname($absolute)) ?? $scope->denyFile($absolute);
    }

    private function schedule(Rule $rule): void
    {
        if ($rule->interval_minutes > 0 && $rule->enabled && !$rule->ephemeral) {
            if ($rule->next_run_at === null) {
                $rule->next_run_at = \Illuminate\Support\Carbon::now();
                $rule->save();
            }
        } elseif ($rule->next_run_at !== null) {
            $rule->next_run_at = null;
            $rule->save();
        }
    }

    /** @return string[]|null null when a pattern is unusable */
    private static function patterns(mixed $value): ?array
    {
        if (is_string($value) && json_decode($value, true) === null) {
            $value = preg_split('/\R/', $value) ?: [];
        }

        $list = Rule::patterns($value);

        foreach ($list as $pattern) {
            if (mb_strlen($pattern) > 200 || str_contains($pattern, "\0")) {
                return null;
            }
        }

        return $list;
    }

    /** The `snapshot_path` setting as a site path, or null when it lies outside the site. */
    private static function snapshotFolder(): ?string
    {
        try {
            $value = trim((string) (evo()->getConfig('snapshot_path') ?? ''));
        } catch (Throwable) {
            $value = '';
        }

        if ($value === '') {
            $value = 'assets/backup';
        }

        if (defined('EVO_BASE_PATH')) {
            $value = str_replace('[(base_path)]', EVO_BASE_PATH, $value);
        }

        $value = str_replace('\\', '/', $value);
        $relative = preg_match('#^(/|[A-Za-z]:/)#', $value) ? Paths::relative($value) : Paths::clean($value);

        return $relative === null || $relative === '' ? null : $relative;
    }
}
