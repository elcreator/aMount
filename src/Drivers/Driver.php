<?php

namespace Elcreator\aMount\Drivers;

use Composer\InstalledVersions;
use Elcreator\aMount\Support\Destination;
use Elcreator\aMount\Support\Lang;
use League\Flysystem\Config as FlysystemConfig;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use RuntimeException;

/**
 * One Flysystem adapter, described well enough for the manager to draw its
 * form, check that its package is installed, and build it from stored settings.
 *
 * Subclasses stay declarative. A field is
 *   ['name', 'label' => lang key, 'type' => text|password|textarea|select|bool|number,
 *    'required' => bool, 'secret' => bool, 'default' => mixed, 'options' => [value => label],
 *    'help' => lang key]
 * `secret` fields are never sent back to the browser: the form shows "set" and
 * an empty box keeps the stored value.
 */
abstract class Driver
{
    public const GROUP_COMPUTER = 'computer'; // the person's own computers (LendSpare), offered first
    public const GROUP_OBJECT = 'object';   // S3 and S3-compatible, GCS, Azure ...
    public const GROUP_CLOUD = 'cloud';     // Google Drive, Dropbox, OneDrive
    public const GROUP_SERVER = 'server';   // SFTP, FTP, WebDAV, local
    public const GROUP_OTHER = 'other';

    abstract public function id(): string;

    abstract public function label(): string;

    public function group(): string
    {
        return self::GROUP_OTHER;
    }

    /** Lang key of a short description shown in the picker. */
    public function description(): string
    {
        return 'aMount::global.driver_' . str_replace('-', '_', $this->id()) . '_desc';
    }

    /** @return array<int, array<string, mixed>> */
    abstract public function fields(): array;

    /** Composer packages this driver needs, name => constraint-free name. @return string[] */
    abstract public function packages(): array;

    /** A class that only exists when the packages are installed. */
    abstract protected function probeClass(): string;

    /** @param array<string, mixed> $config */
    abstract protected function adapter(array $config, array $options): FilesystemAdapter;

    /**
     * Whether the adapter honours Flysystem visibility (an ACL, a Unix mode).
     * Most cloud adapters throw on `setVisibility()`, and S3/GCS buckets with
     * ACLs disabled reject it, so it is applied only when the mount opts in.
     */
    public function supportsVisibility(): bool
    {
        return false;
    }

    /**
     * Whether a write that fails leaves nothing behind (the store writes under a temporary
     * name and renames). The syncer then does not "clean up" after a refused write - on a
     * computer that would delete the person's own, newer file.
     */
    public function writesAtomically(): bool
    {
        return false;
    }

    /**
     * Whether the settings name a path on this machine (a folder, a ZIP file).
     * Such a mount reads and writes anything the PHP process can reach, outside
     * the file manager's root, so only an administrator may configure one.
     */
    public function touchesHost(): bool
    {
        return false;
    }

    /**
     * The network destinations the adapter connects to, field => URL or host,
     * as configured. A mount not configured by an administrator may reach only
     * public addresses ({@see Destination}).
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public function destinations(array $config): array
    {
        return [];
    }

    /** Whether the settings can carry a public base URL the adapter cannot work out itself. */
    public function needsPublicUrl(): bool
    {
        return false;
    }

    public function available(): bool
    {
        return class_exists($this->probeClass()) || interface_exists($this->probeClass());
    }

    /**
     * The packages Composer has not installed. Can be empty while the driver is
     * still unavailable: FTP also needs PHP's ftp extension, which no package brings.
     *
     * @return string[]
     */
    public function missingPackages(): array
    {
        if (!class_exists(InstalledVersions::class)) {
            return $this->available() ? [] : $this->packages();
        }

        return array_values(array_filter($this->packages(), static fn (string $package): bool => !InstalledVersions::isInstalled($package)));
    }

    /** The command for a shell; {@see \Elcreator\aMount\Console\DriversCommand} runs `package:installrequire` per package. */
    public function installCommand(): string
    {
        return 'cd core && php artisan amount:drivers --install=' . $this->id();
    }

    /** Settings errors, field => message key. Empty when usable. @return array<string, string> */
    public function validate(array $config): array
    {
        $errors = [];

        foreach ($this->fields() as $field) {
            if (!empty($field['required']) && trim((string) ($config[$field['name']] ?? '')) === '' && ($field['type'] ?? '') !== 'bool') {
                $errors[$field['name']] = 'aMount::global.error_field_required';
            }
        }

        return $errors;
    }

    /** Defaults for a fresh form. @return array<string, mixed> */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->fields() as $field) {
            if (array_key_exists('default', $field)) {
                $defaults[$field['name']] = $field['default'];
            }
        }

        return $defaults;
    }

    /**
     * `$options['_trusted']` says an administrator (or the site configuration)
     * chose these settings. Without it the host-touching drivers refuse and
     * every destination must resolve to public addresses; the checked
     * addresses reach the adapter as `$options['_pinned']` (host => address).
     *
     * @throws RuntimeException
     */
    public function filesystem(array $config, array $options = []): Filesystem
    {
        if (!$this->available()) {
            throw new RuntimeException(sprintf(
                '%s needs %s. Install it with: %s',
                $this->label(),
                implode(', ', $this->packages()),
                $this->installCommand()
            ));
        }

        $config = $config + $this->defaults();
        $errors = $this->validate($config);

        if ($errors !== []) {
            throw new RuntimeException($this->label() . ': missing ' . implode(', ', array_keys($errors)) . '.');
        }

        if (empty($options['_trusted'])) {
            if ($this->touchesHost()) {
                throw new RuntimeException(Lang::get('aMount::global.error_driver_admin_only', ['driver' => $this->label()]));
            }

            $options['_pinned'] = $this->pinDestinations($config);
        }

        // Visibility is passed per write (see writeConfig()), not as a default.
        return new Filesystem($this->adapter($config, $options));
    }

    /**
     * Resolve and check every destination, host => the address to connect to.
     *
     * @return array<string, string>
     * @throws RuntimeException naming the refused host
     */
    public function pinDestinations(array $config): array
    {
        $pinned = [];

        foreach ($this->destinations($config) as $value) {
            if (trim($value) === '') {
                continue;
            }

            $found = Destination::inspect($value);

            if ($found['status'] !== Destination::OK) {
                throw new RuntimeException(Lang::get('aMount::global.error_destination_' . $found['status'], ['host' => $found['host'] ?? $value]));
            }

            $pinned[(string) $found['host']] = (string) $found['address'];
        }

        return $pinned;
    }

    /** Flysystem write config for one file. */
    public function writeConfig(array $options, string $visibility): array
    {
        $config = [];

        if ($this->supportsVisibility() && !empty($options['apply_visibility'])) {
            $config[FlysystemConfig::OPTION_VISIBILITY] = $visibility;
        }

        return $config;
    }

    /** Description of the driver for the API. */
    public function describe(): array
    {
        return [
            'id' => $this->id(),
            'label' => $this->label(),
            'group' => $this->group(),
            'description' => $this->description(),
            'available' => $this->available(),
            'packages' => $this->packages(),
            'install' => $this->installCommand(),
            'fields' => $this->fields(),
            'defaults' => $this->defaults(),
            'supports_visibility' => $this->supportsVisibility(),
            'needs_public_url' => $this->needsPublicUrl(),
            'admin_only' => $this->touchesHost(),
        ];
    }

    // ------------------------------------------------------------------
    // Field helpers for subclasses
    // ------------------------------------------------------------------

    protected static function text(string $name, bool $required = false, array $extra = []): array
    {
        return ['name' => $name, 'type' => 'text', 'required' => $required, 'label' => 'aMount::global.f_' . $name] + $extra;
    }

    protected static function secret(string $name, bool $required = false, array $extra = []): array
    {
        return ['name' => $name, 'type' => 'password', 'required' => $required, 'secret' => true, 'label' => 'aMount::global.f_' . $name] + $extra;
    }

    protected static function area(string $name, bool $required = false, array $extra = []): array
    {
        return ['name' => $name, 'type' => 'textarea', 'required' => $required, 'secret' => true, 'label' => 'aMount::global.f_' . $name] + $extra;
    }

    protected static function number(string $name, int $default, array $extra = []): array
    {
        return ['name' => $name, 'type' => 'number', 'default' => $default, 'label' => 'aMount::global.f_' . $name] + $extra;
    }

    protected static function toggle(string $name, bool $default = false, array $extra = []): array
    {
        return ['name' => $name, 'type' => 'bool', 'default' => $default, 'label' => 'aMount::global.f_' . $name] + $extra;
    }

    protected static function select(string $name, array $options, $default = null, bool $required = false): array
    {
        return ['name' => $name, 'type' => 'select', 'options' => $options, 'default' => $default, 'required' => $required, 'label' => 'aMount::global.f_' . $name];
    }

    /** The prefix every adapter takes: trimmed, no edge slashes. */
    protected static function prefix(array $config, string $key = 'prefix'): string
    {
        return trim((string) ($config[$key] ?? ''), '/');
    }
}
