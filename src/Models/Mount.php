<?php

namespace Elcreator\aMount\Models;

use Elcreator\aMount\Drivers\Drivers;
use Elcreator\aMount\Support\Config;
use Elcreator\aMount\Support\Crypt;
use Elcreator\aMount\Support\Principal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use RuntimeException;

/**
 * A remote disk: a Flysystem driver plus credentials.
 *
 * Two shapes, told apart by `owner_id`:
 *  - personal (an id): one manager's own account. Nobody else can use it, see
 *    its settings or read its credentials, administrators included. Another
 *    administrator may delete it, never open it.
 *  - site-wide (null): shared credentials, usable by managers who hold `amount`
 *    and — when groups are granted — belong to one of them.
 *
 * A mount can also come from configuration (`options.source = config`). Its
 * credentials are then read from config/env at use and never stored.
 */
class Mount extends Model
{
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';
    public const STATUS_UNTESTED = 'untested';

    protected $table = 'amount_mounts';

    protected $guarded = [];

    protected $casts = [
        'options_json' => 'array',
        'usage_json' => 'array',
        'last_checked_at' => 'datetime',
        'owner_id' => 'integer',
    ];

    /** The settings that are not secrets: shown back to the user as they are. */
    public function options(): array
    {
        return is_array($this->options_json) ? $this->options_json : [];
    }

    public function option(string $key, $default = null)
    {
        return $this->options()[$key] ?? $default;
    }

    public function isPersonal(): bool
    {
        return $this->owner_id !== null;
    }

    public function isFromConfig(): bool
    {
        return $this->option('source') === 'config';
    }

    /** Decrypted connection settings. Never hand the result to a response as is. */
    public function credentials(): array
    {
        if ($this->isFromConfig()) {
            $definition = (array) Config::get('mounts.' . $this->option('config_key', ''), []);

            return (array) ($definition['config'] ?? []);
        }

        $json = Crypt::decrypt($this->config_enc);

        if ($json === null) {
            return [];
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /** True when something is stored that this server cannot decrypt (the secret changed). */
    public function credentialsUnreadable(): bool
    {
        return !$this->isFromConfig()
            && trim((string) $this->config_enc) !== ''
            && Crypt::decrypt($this->config_enc) === null;
    }

    public function setCredentials(array $config): void
    {
        $this->config_enc = Crypt::encrypt((string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** User groups granted a site-wide mount. Empty = every manager with `amount`. @return int[] */
    public function grantedGroups(): array
    {
        if ($this->isFromConfig()) {
            return array_map('intval', (array) (Config::get('mounts.' . $this->option('config_key', '') . '.groups') ?? []));
        }

        return DB::table('amount_mount_groups')->where('mount_id', $this->getKey())
            ->pluck('user_group')->map(static fn ($id) => (int) $id)->all();
    }

    /** @param int[] $groups */
    public function grantGroups(array $groups): void
    {
        $groups = array_values(array_unique(array_filter(array_map('intval', $groups))));

        DB::table('amount_mount_groups')->where('mount_id', $this->getKey())->delete();

        foreach ($groups as $group) {
            DB::table('amount_mount_groups')->insert(['mount_id' => $this->getKey(), 'user_group' => $group]);
        }
    }

    /** @throws RuntimeException when the driver's package is missing or the settings are unusable */
    public function filesystem(): Filesystem
    {
        // Whose key a driver resolving one per manager uses (ComputerDriver): the owner's, or for a
        // shared mount the manager who set it up.
        return Drivers::get($this->driver)->filesystem($this->credentials(), $this->options() + [
            '_owner_id' => $this->owner_id ?? $this->configuredBy(),
            '_trusted' => $this->trusted(),
        ]);
    }

    /**
     * Who last set the connection settings. Rows saved before this was recorded
     * fall back to the owner, then to the creator.
     */
    public function configuredBy(): ?int
    {
        $id = (int) ($this->option('configured_by') ?? $this->owner_id ?? $this->created_by ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Whether the settings were chosen by someone allowed to point a mount at
     * this machine or an internal network: the site configuration, or an
     * account that is an administrator *now* (a demoted one loses it on the next run).
     */
    public function trusted(): bool
    {
        if ($this->isFromConfig()) {
            return true;
        }

        $by = $this->configuredBy();

        return $by !== null && Principal::forUser($by)->isAdministrator();
    }

    public function rules()
    {
        return $this->hasMany(Rule::class, 'mount_id');
    }

    public static function newUuid(): string
    {
        return (string) Str::uuid();
    }

    /** "public_url" with no trailing slash, or ''. */
    public function publicUrl(): string
    {
        return rtrim(trim((string) $this->option('public_url', '')), '/');
    }
}
