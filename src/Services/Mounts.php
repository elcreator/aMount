<?php

namespace Elcreator\aMount\Services;

use Elcreator\aMount\Drivers\Driver;
use Elcreator\aMount\Drivers\Drivers;
use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Support\Config;
use Elcreator\aMount\Support\Paths;
use Elcreator\aMount\Support\Principal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The mounts one manager may see and use — and the only door to them.
 *
 * Visibility is decided here and nowhere else:
 *  - a personal mount belongs to its owner alone. Everybody else, other
 *    administrators included, gets "not found": the answer must not even
 *    confirm that it exists. An administrator may *delete* one (a departed
 *    colleague's Drive), never open it.
 *  - a site mount is usable by managers holding `amount` who belong to one of
 *    its granted groups (no grant = everybody). Holders of `amount_manage` see
 *    every site mount, as they administer them.
 *  - a config mount is a site mount that cannot be edited.
 *
 * Credentials never leave through this class: {@see present()} masks every
 * field flagged `secret` by its driver, and error text is scrubbed of them.
 */
final class Mounts
{
    public const MASK = '********';

    public function __construct(private readonly Actor $actor)
    {
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /** @return Collection<int, Mount> */
    public function visible(): Collection
    {
        if (!$this->actor->mayUse()) {
            return new Collection();
        }

        self::syncConfigMounts();

        return Mount::query()->orderBy('name')->get()
            ->filter(fn (Mount $m) => $this->canUse($m))
            ->values();
    }

    /** @throws AmountException */
    public function find(string $uuid): Mount
    {
        if (!$this->actor->mayUse()) {
            throw AmountException::forbidden();
        }

        self::syncConfigMounts();

        $mount = Mount::query()->where('uuid', trim($uuid))->first();

        if ($mount === null || !$this->canUse($mount)) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_mount'));
        }

        return $mount;
    }

    /**
     * The mount an administrator may delete: usable ones, plus personal mounts
     * of other people — which can be removed but, having no other door, never opened.
     *
     * @throws AmountException
     */
    public function findForDelete(string $uuid): Mount
    {
        $mount = Mount::query()->where('uuid', trim($uuid))->first();

        if ($mount !== null && $mount->isPersonal() && $this->actor->mayManage()) {
            return $mount;
        }

        return $this->find($uuid);
    }

    /**
     * Personal mounts of other people, for the administrators' "remove" list.
     * Name, driver and owner only.
     *
     * @return array<int, array<string, mixed>>
     */
    public function foreign(): array
    {
        if (!$this->actor->mayManage()) {
            return [];
        }

        return Mount::query()->whereNotNull('owner_id')->where('owner_id', '!=', $this->actor->userId())
            ->orderBy('name')->get()
            ->map(static fn (Mount $m) => [
                'uuid' => $m->uuid, 'name' => $m->name, 'driver' => $m->driver, 'owner_id' => (int) $m->owner_id,
            ])->all();
    }

    public function canUse(Mount $mount): bool
    {
        if (!$this->actor->mayUse()) {
            return false;
        }

        if ($mount->isPersonal()) {
            return (int) $mount->owner_id === $this->actor->userId();
        }

        if ($this->actor->mayManage()) {
            return true;
        }

        $groups = $mount->grantedGroups();

        return $groups === [] || array_intersect($groups, $this->actor->principal()->userGroups()) !== [];
    }

    public function canEdit(Mount $mount): bool
    {
        if ($mount->isFromConfig()) {
            return false;
        }

        if ($mount->isPersonal()) {
            return (int) $mount->owner_id === $this->actor->userId() && $this->actor->mayUse();
        }

        return $this->actor->mayManage();
    }

    /** Whether the actor may remove it: editors, and administrators for other people's personal mounts. */
    public function canDelete(Mount $mount): bool
    {
        if ($mount->isFromConfig()) {
            return false;
        }

        if ($mount->isPersonal() && $this->actor->mayManage()) {
            return true;
        }

        return $this->canEdit($mount);
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /**
     * @param array{name?:string, driver?:string, scope?:string, config?:array, options?:array, groups?:array} $in
     * @throws AmountException
     */
    public function create(array $in): Mount
    {
        $this->actor->authorize();

        $driver = $this->driverFor((string) ($in['driver'] ?? ''));
        $personal = ($in['scope'] ?? 'personal') !== 'site';

        if (!$personal && !$this->actor->mayManage()) {
            throw AmountException::forbidden();
        }

        $name = $this->name($in['name'] ?? '');
        $config = $this->config($driver, (array) ($in['config'] ?? []), []);

        $mount = new Mount();
        $mount->uuid = Mount::newUuid();
        $mount->name = $name;
        $mount->driver = $driver->id();
        $mount->owner_id = $personal ? $this->actor->userId() : null;
        $mount->created_by = $this->actor->userId();
        $mount->status = Mount::STATUS_UNTESTED;
        $mount->options_json = $this->options((array) ($in['options'] ?? []), []);
        $mount->setCredentials($config);
        $mount->save();

        if (!$personal) {
            $mount->grantGroups((array) ($in['groups'] ?? []));
        }

        return $mount;
    }

    /**
     * A blank secret field keeps the stored value: the form never receives
     * secrets, so an empty box can only mean "unchanged".
     *
     * @throws AmountException
     */
    public function update(Mount $mount, array $in): Mount
    {
        if (!$this->canEdit($mount)) {
            throw $this->canUse($mount) ? AmountException::forbidden() : AmountException::notFound(AmountException::text('aMount::global.what_mount'));
        }

        $driver = Drivers::get($mount->driver);

        if (array_key_exists('name', $in)) {
            $mount->name = $this->name($in['name']);
        }

        if (array_key_exists('config', $in)) {
            $mount->setCredentials($this->config($driver, (array) $in['config'], $mount->credentials()));
            $mount->status = Mount::STATUS_UNTESTED;
            $mount->last_error = null;
        }

        if (array_key_exists('options', $in)) {
            $mount->options_json = $this->options((array) $in['options'], $mount->options());
        }

        $mount->save();

        if (!$mount->isPersonal() && array_key_exists('groups', $in)) {
            $mount->grantGroups((array) $in['groups']);
        }

        return $mount;
    }

    /**
     * Removes the mount and, with $force, the rules and index rows that use it.
     * The objects on the store itself are never touched.
     *
     * @throws AmountException
     */
    public function delete(Mount $mount, bool $force = false): void
    {
        if (!$this->canDelete($mount)) {
            throw $this->canUse($mount) ? AmountException::forbidden() : AmountException::notFound(AmountException::text('aMount::global.what_mount'));
        }

        $ruleIds = Rule::query()->where('mount_id', $mount->getKey())->pluck('id')->all();

        if ($ruleIds !== [] && !$force) {
            throw AmountException::invalid('error_mount_in_use', ['count' => count($ruleIds)]);
        }

        self::purge($mount);
    }

    /** Remove a mount with everything indexed under it. Authorisation is the caller's job. */
    public static function purge(Mount $mount): void
    {
        DB::transaction(static function () use ($mount) {
            $ruleIds = Rule::query()->where('mount_id', $mount->getKey())->pluck('id')->all();

            Run::query()->whereIn('rule_id', $ruleIds)->delete();
            FileEntry::query()->where(static function ($q) use ($ruleIds, $mount) {
                $q->whereIn('rule_id', $ruleIds)->orWhere('mount_id', $mount->getKey());
            })->delete();
            Rule::query()->whereIn('id', $ruleIds)->delete();
            DB::table('amount_mount_groups')->where('mount_id', $mount->getKey())->delete();
            $mount->delete();
        });
    }

    // ------------------------------------------------------------------
    // Talking to the store
    // ------------------------------------------------------------------

    /**
     * Write, read back and delete a probe object; record the verdict.
     *
     * @return array{ok:bool, message:string}
     */
    public function test(Mount $mount): array
    {
        if (!$this->canUse($mount)) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_mount'));
        }

        $result = $this->probe($mount);

        $mount->status = $result['ok'] ? Mount::STATUS_OK : Mount::STATUS_ERROR;
        $mount->last_error = $result['ok'] ? null : $result['message'];
        $mount->last_checked_at = \Illuminate\Support\Carbon::now();
        $mount->save();

        return $result;
    }

    /**
     * Write, read back and delete a probe object on the store the mount points
     * at. Touches nothing in the database.
     *
     * @return array{ok:bool, message:string}
     */
    private function probe(Mount $mount): array
    {
        $key = Paths::remote($this->base($mount), '.amount-probe-' . bin2hex(random_bytes(6)));
        $payload = 'aMount ' . bin2hex(random_bytes(8));

        try {
            $fs = $mount->filesystem();
            $fs->write($key, $payload);
            $read = $fs->read($key);
            $fs->delete($key);

            if ($read !== $payload) {
                throw new \RuntimeException(AmountException::text('aMount::global.error_probe_mismatch'));
            }

            return ['ok' => true, 'message' => AmountException::text('aMount::global.test_ok')];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => self::redact($e->getMessage(), $mount)];
        }
    }

    /**
     * Test the settings of a form that was not saved (yet): same validation and
     * access rules as {@see create()} / {@see update()}, same probe as {@see test()},
     * but nothing is stored. With $uuid the stored secrets stand in for blank
     * secret fields. The answer never carries a secret ({@see redact()}).
     *
     * @param array{driver?:string, scope?:string, config?:array, options?:array} $in
     * @return array{ok:bool, message:string}
     * @throws AmountException
     */
    public function testDraft(array $in, ?string $uuid = null): array
    {
        $this->actor->authorize();

        $existing = $uuid === null ? null : $this->find($uuid);

        if ($existing !== null && !$this->canEdit($existing)) {
            throw $this->canUse($existing) ? AmountException::forbidden() : AmountException::notFound(AmountException::text('aMount::global.what_mount'));
        }

        $personal = $existing ? $existing->isPersonal() : (($in['scope'] ?? 'personal') !== 'site');

        if (!$personal && $existing === null && !$this->actor->mayManage()) {
            throw AmountException::forbidden();
        }

        $driver = $existing ? Drivers::get($existing->driver) : $this->driverFor((string) ($in['driver'] ?? ''));
        $config = $this->config($driver, (array) ($in['config'] ?? []), $existing ? $existing->credentials() : []);

        // A throwaway model: it only carries the settings to the driver and is never saved.
        $draft = new Mount();
        $draft->driver = $driver->id();
        $draft->owner_id = $personal ? $this->actor->userId() : null;
        $draft->options_json = $this->options((array) ($in['options'] ?? []), $existing ? $existing->options() : []);
        $draft->setCredentials($config);

        return $this->probe($draft);
    }

    /**
     * Remote sub-folders below $path (relative to the actor's remote base).
     *
     * @return array{path:string, folders:array<int, array{name:string, path:string}>}
     */
    public function browse(Mount $mount, string $path = ''): array
    {
        if (!$this->canUse($mount)) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_mount'));
        }

        $clean = Paths::clean($path);

        if ($clean === null) {
            throw AmountException::invalid('error_path_invalid');
        }

        $base = $this->base($mount);
        $folders = [];

        try {
            foreach ($mount->filesystem()->listContents(Paths::remote($base, $clean), false) as $item) {
                if (!$item->isDir()) {
                    continue;
                }

                $name = basename(trim($item->path(), '/'));

                if ($name === '' || count($folders) >= 500) {
                    continue;
                }

                $folders[] = ['name' => $name, 'path' => Paths::remote($clean, $name)];
            }
        } catch (Throwable $e) {
            throw new AmountException('browse_failed', self::redact($e->getMessage(), $mount), 502);
        }

        usort($folders, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return ['path' => $clean, 'folders' => $folders];
    }

    /**
     * What the actor's keys on this mount start with: the mount's root, plus
     * `u<id>/` for a manager who may not administer site mounts, so one
     * manager's uploads cannot land in, overwrite or list another's.
     */
    public function base(Mount $mount): string
    {
        return self::remoteBase($mount, $this->actor->principal());
    }

    public static function remoteBase(Mount $mount, Principal $principal): string
    {
        $root = Paths::clean((string) $mount->option('root', '')) ?? '';
        $jail = !$mount->isPersonal() && !$principal->mayManage() ? 'u' . $principal->id : '';

        return Paths::remote($root, $jail);
    }

    // ------------------------------------------------------------------
    // Finding a mount from words
    // ------------------------------------------------------------------

    /**
     * "put it on my Google Drive": match name, driver id/label or slug.
     * One match wins; several are ambiguous (the caller asks); none is empty.
     * An exact name beats a fuzzy one, so "Work" is not ambiguous with "Work 2".
     *
     * @return array{mount:?Mount, candidates:array<int, Mount>}
     */
    public function resolve(?string $hint): array
    {
        $all = $this->visible()->all();
        $needle = self::words((string) $hint);

        if ($needle === '') {
            return ['mount' => count($all) === 1 ? $all[0] : null, 'candidates' => $all];
        }

        $exact = array_values(array_filter($all, static fn (Mount $m) => self::words($m->name) === $needle));

        if (count($exact) === 1) {
            return ['mount' => $exact[0], 'candidates' => $exact];
        }

        $matches = array_values(array_filter($all, static function (Mount $m) use ($needle) {
            $driver = Drivers::has($m->driver) ? Drivers::get($m->driver) : null;
            $haystacks = array_filter([
                self::words($m->name),
                self::words(Str::slug($m->name, ' ')),
                self::words($m->driver),
                $driver ? self::words($driver->label()) : '',
            ]);

            foreach ($haystacks as $h) {
                if (str_contains($h, $needle) || (strlen($h) >= 3 && str_contains($needle, $h))) {
                    return true;
                }
            }

            return false;
        }));

        if ($exact !== []) {
            $matches = $exact;
        }

        return ['mount' => count($matches) === 1 ? $matches[0] : null, 'candidates' => $matches];
    }

    // ------------------------------------------------------------------
    // API shape
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function present(Mount $mount): array
    {
        $driver = Drivers::has($mount->driver) ? Drivers::get($mount->driver) : null;
        $stored = $mount->credentials();
        $config = [];
        $set = [];

        foreach ($driver ? $driver->fields() : [] as $field) {
            $name = $field['name'];
            $has = array_key_exists($name, $stored) && trim((string) $stored[$name]) !== '';

            if (!empty($field['secret'])) {
                $config[$name] = $has ? self::MASK : '';
                $set[$name] = $has;
            } else {
                $config[$name] = $stored[$name] ?? ($field['default'] ?? '');
            }
        }

        $options = $mount->options();
        unset($options['config_key']);

        return [
            'uuid' => $mount->uuid,
            'name' => $mount->name,
            'driver' => $mount->driver,
            'driver_label' => $driver ? $driver->label() : $mount->driver,
            'scope' => $mount->isFromConfig() ? 'config' : ($mount->isPersonal() ? 'personal' : 'site'),
            'status' => $mount->status,
            'last_error' => $mount->last_error,
            'last_checked_at' => $mount->last_checked_at?->toIso8601String(),
            'options' => $options,
            'config' => $config,
            'secrets_set' => $set,
            'groups' => $mount->isPersonal() ? [] : $mount->grantedGroups(),
            'credentials_unreadable' => $mount->credentialsUnreadable(),
            'usage' => $mount->usage_json,
            'index' => (new Files($this->actor))->indexTotals(null, $mount),
            'editable' => $this->canEdit($mount),
            'deletable' => $this->canDelete($mount),
        ];
    }

    /** The short form for pickers and for AI agents: no settings at all. @return array<string, mixed> */
    public function summary(Mount $mount): array
    {
        $driver = Drivers::has($mount->driver) ? Drivers::get($mount->driver) : null;

        return [
            'uuid' => $mount->uuid,
            'name' => $mount->name,
            'driver' => $mount->driver,
            'driver_label' => $driver ? $driver->label() : $mount->driver,
            'scope' => $mount->isFromConfig() ? 'config' : ($mount->isPersonal() ? 'personal' : 'site'),
            'status' => $mount->status,
        ];
    }

    // ------------------------------------------------------------------
    // Config mounts
    // ------------------------------------------------------------------

    /**
     * Mirror `aMount.mounts` into rows so rules can point at them. Only
     * metadata is stored: credentials are read from configuration at use.
     */
    public static function syncConfigMounts(): void
    {
        $defined = (array) Config::get('mounts', []);
        $keep = [];

        foreach ($defined as $key => $definition) {
            if (!is_array($definition) || !Drivers::has((string) ($definition['driver'] ?? ''))) {
                continue;
            }

            $uuid = self::configUuid((string) $key);
            $keep[] = $uuid;
            $options = (array) ($definition['options'] ?? []) + ['source' => 'config', 'config_key' => (string) $key];
            $options['source'] = 'config';
            $options['config_key'] = (string) $key;

            $mount = Mount::query()->where('uuid', $uuid)->first() ?? new Mount(['uuid' => $uuid, 'status' => Mount::STATUS_UNTESTED]);
            $mount->fill([
                'name' => (string) ($definition['name'] ?? $key),
                'driver' => (string) $definition['driver'],
                'owner_id' => null,
                'options_json' => $options,
            ]);

            if ($mount->isDirty() || !$mount->exists) {
                $mount->save();
            }
        }

        // A definition that was removed: drop its row unless rules still depend on it.
        foreach (Mount::query()->where('options_json', 'like', '%"source":"config"%')->get() as $row) {
            if (!in_array($row->uuid, $keep, true) && !Rule::query()->where('mount_id', $row->getKey())->exists()) {
                self::purge($row);
            }
        }
    }

    public static function configUuid(string $key): string
    {
        $h = md5('amount-config:' . $key);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), '4' . substr($h, 13, 3), '8' . substr($h, 17, 3), substr($h, 20, 12));
    }

    /** Remove known secret values from a message before it is stored or shown. */
    public static function redact(string $message, Mount $mount): string
    {
        $secrets = [];

        try {
            $driver = Drivers::has($mount->driver) ? Drivers::get($mount->driver) : null;
            $stored = $mount->credentials();

            foreach ($driver ? $driver->fields() : [] as $field) {
                $value = trim((string) ($stored[$field['name']] ?? ''));

                if (!empty($field['secret']) && strlen($value) >= 4) {
                    $secrets[] = $value;
                }
            }
        } catch (Throwable) {
        }

        usort($secrets, static fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($secrets as $secret) {
            $message = str_replace($secret, self::MASK, $message);
        }

        return mb_substr($message, 0, 500);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function driverFor(string $id): Driver
    {
        if (!Drivers::has($id)) {
            throw AmountException::invalid('error_driver_unknown');
        }

        $driver = Drivers::get($id);

        if (!$driver->available()) {
            throw AmountException::invalid('error_driver_unavailable', ['driver' => $driver->label(), 'command' => $driver->installCommand()]);
        }

        return $driver;
    }

    private function name(mixed $name): string
    {
        $name = trim((string) $name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw AmountException::fields(['name' => AmountException::text('aMount::global.error_field_required')]);
        }

        return $name;
    }

    /**
     * Keep only the driver's own fields; a blank secret keeps the stored value.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function config(Driver $driver, array $in, array $stored): array
    {
        $config = [];

        foreach ($driver->fields() as $field) {
            $name = $field['name'];
            $value = $in[$name] ?? null;

            if (!empty($field['secret']) && ($value === null || trim((string) $value) === '' || $value === self::MASK)) {
                if (isset($stored[$name]) && $stored[$name] !== '') {
                    $config[$name] = $stored[$name];
                }

                continue;
            }

            if ($value === null) {
                if (array_key_exists($name, $stored)) {
                    $config[$name] = $stored[$name];
                }

                continue;
            }

            $config[$name] = match ($field['type'] ?? 'text') {
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'number' => (int) $value,
                default => trim((string) $value),
            };
        }

        $errors = $driver->validate($config + $driver->defaults());

        if ($errors !== []) {
            throw AmountException::fields(array_map(static fn ($key) => AmountException::text($key), $errors));
        }

        return $config;
    }

    /** @return array<string, mixed> */
    private function options(array $in, array $stored): array
    {
        $options = array_intersect_key($stored, array_flip(['source', 'config_key']));
        $errors = [];

        $url = trim((string) ($in['public_url'] ?? ($stored['public_url'] ?? '')));

        if ($url !== '' && !preg_match('#^https?://[^\s/]+#i', $url)) {
            $errors['public_url'] = AmountException::text('aMount::global.error_public_url');
        } elseif ($url !== '') {
            $options['public_url'] = rtrim($url, '/');
        }

        $serve = strtolower(trim((string) ($in['serve'] ?? ($stored['serve'] ?? 'auto'))));

        if (in_array($serve, ['redirect', 'proxy'], true)) {
            $options['serve'] = $serve;
        }

        $root = Paths::clean((string) ($in['root'] ?? ($stored['root'] ?? '')));

        if ($root === null) {
            $errors['root'] = AmountException::text('aMount::global.error_path_invalid');
        } elseif ($root !== '') {
            $options['root'] = $root;
        }

        if (!empty($in['apply_visibility'] ?? ($stored['apply_visibility'] ?? false))) {
            $options['apply_visibility'] = true;
        }

        if ($errors !== []) {
            throw AmountException::fields($errors);
        }

        return $options;
    }

    private static function words(string $text): string
    {
        $text = mb_strtolower($text);
        $text = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
        $drop = ['my', 'the', 'our', 'a', 'to', 'on', 'in', 'mount'];

        return implode(' ', array_filter(explode(' ', $text), static fn ($w) => $w !== '' && !in_array($w, $drop, true)));
    }
}
