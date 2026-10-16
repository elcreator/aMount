<?php

namespace Elcreator\aMount\Support;

use EvolutionCMS\Support\FileManagerAccess;
use Generator;

/**
 * What one manager can see in the file manager — and therefore the most they
 * can ever offload.
 *
 * The rule this class exists to enforce: **a manager must not be able to back
 * up, publish or delete anything they cannot see themselves.** The file
 * manager decides visibility in three layers (see AGENTS.md of the CMS, *File
 * manager access rules*); each is applied here to every folder and file the
 * scanner meets, not once to the rule's root:
 *
 *  1. the root. Nothing outside the manager's own `filemanager_path`.
 *  2. folders by permission. `temp/backup`, `assets/backup` need `bk_manager`,
 *     `assets/plugins` needs `save_plugin`, and so on; `core/`, `manager/` and
 *     the view paths need a code permission; everything else needs
 *     `file_manager`. A folder protected for this manager is pruned, even when
 *     it sits inside an allowed rule folder.
 *  3. file groups (`file_groups`, only with `use_udperms`). A folder or file
 *     restricted to document groups the manager is not in is invisible, and so
 *     is everything below it. Administrators (role 1) are exempt.
 *
 * Links are never followed or listed (the core's file manager does not list
 * them either, because a listed entry would get a direct URL).
 *
 * The decision is made from a {@see Principal}, so it is the same for a click
 * in the manager and a queued run.
 */
final class Scope
{
    /** Folders that open with one specific permission (the core's `fileManagerProtectedPaths()` table). */
    private const BY_PERMISSION = [
        'bk_manager' => ['temp/backup', 'assets/backup'],
        'save_plugin' => ['assets/plugins'],
        'save_snippet' => ['assets/snippets'],
        'save_template' => ['assets/templates'],
        'save_module' => ['assets/modules'],
        'empty_cache' => ['assets/cache'],
        'import_static' => ['temp/import', 'assets/import'],
        'export_static' => ['temp/export', 'assets/export'],
    ];

    private const CODE_PERMISSIONS = ['save_snippet', 'save_plugin', 'save_module'];

    /** file_groups rows already loaded, keyed by ACL key */
    private array $restrictions = [];

    /** subtree keys whose file_groups were loaded in full */
    private array $preloaded = [];

    private ?array $protected = null;

    private ?array $opened = null;

    private function __construct(private readonly Principal $principal)
    {
    }

    public static function for(Principal $principal): self
    {
        return new self($principal);
    }

    public static function forUser(int $userId): self
    {
        return new self(Principal::forUser($userId));
    }

    public function principal(): Principal
    {
        return $this->principal;
    }

    /** The manager's file root, absolute. */
    public function root(): string
    {
        return $this->principal->root;
    }

    // ------------------------------------------------------------------
    // Verdicts
    // ------------------------------------------------------------------

    /**
     * May this manager see this folder? (The check for a rule's root and for
     * each folder the walker enters.)
     *
     * @return string|null null when allowed, otherwise the reason code:
     *                     inactive | outside_root | protected | no_file_manager | restricted | link | reserved
     */
    public function denyDirectory(string $absolute): ?string
    {
        $absolute = $this->normalize($absolute);

        if (($reason = $this->denyCommon($absolute)) !== null) {
            return $reason;
        }

        // A link anywhere on the way is refused as a whole. realpath() resolves
        // links and Windows junctions alike; the expected spelling is the
        // canonical root plus whatever follows it.
        $real = realpath($absolute);

        if ($real === false) {
            return 'outside_root';
        }

        $real = rtrim(str_replace('\\', '/', $real), '/');
        $root = rtrim($this->principal->root, '/');
        $rootReal = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
        $expected = $rootReal . substr($absolute, strlen($root));

        if (!$this->same($real, $expected)) {
            return 'link';
        }

        return null;
    }

    /** Same question for one file; the folder it sits in has been checked by the walker. */
    public function denyFile(string $absolute): ?string
    {
        $absolute = $this->normalize($absolute);

        if (is_link($absolute)) {
            return 'link';
        }

        return $this->denyCommon($absolute);
    }

    public function allowsDirectory(string $absolute): bool
    {
        return $this->denyDirectory($absolute) === null;
    }

    public function allowsFile(string $absolute): bool
    {
        return $this->denyFile($absolute) === null;
    }

    /**
     * May this manager change or delete this existing file? Needed before a
     * `move` rule removes the local copy and before a mirror deletes remotely.
     *
     * Stricter than seeing, exactly as in the file manager: it takes
     * `file_manager` (or the folder's own permission is not enough to delete),
     * and a restricted manager may not modify a top-level entry of their root.
     */
    public function canModify(string $absolute): bool
    {
        $absolute = $this->normalize($absolute);

        if (!$this->principal->can('file_manager') || $this->denyFile($absolute) !== null) {
            return false;
        }

        if (!$this->principal->isRestrictedByGroups()) {
            return true;
        }

        $below = $this->belowRoot($absolute);

        if ($below === null || FileManagerAccess::isTopLevelPath($below)) {
            return false;
        }

        return true;
    }

    /**
     * May this manager put a new file into this existing folder? Needed before
     * a download writes back to the site.
     *
     * Seeing the folder is not enough, exactly as for deleting: it takes
     * `file_manager`, and a restricted manager may not add a top-level entry to
     * their root.
     */
    public function canCreateIn(string $absoluteDirectory): bool
    {
        $absoluteDirectory = $this->normalize($absoluteDirectory);

        if (!$this->principal->can('file_manager') || $this->denyDirectory($absoluteDirectory) !== null) {
            return false;
        }

        if (!$this->principal->isRestrictedByGroups()) {
            return true;
        }

        return $this->belowRoot($absoluteDirectory) !== '' && $this->belowRoot($absoluteDirectory) !== null;
    }

    // ------------------------------------------------------------------
    // Walking
    // ------------------------------------------------------------------

    /**
     * Every file this manager may see below $directory, links skipped, folders
     * they may not see pruned with everything under them.
     *
     * @return Generator<int, array{path: string, size: int, mtime: int}> absolute path, bytes, mtime
     */
    public function files(string $directory, bool $recursive = true): Generator
    {
        $directory = $this->normalize($directory);

        if ($this->denyDirectory($directory) !== null) {
            return;
        }

        $this->preload($directory);

        $stack = [$directory];

        while ($stack !== []) {
            $current = array_pop($stack);
            $entries = @scandir($current);

            if ($entries === false) {
                continue;
            }

            sort($entries, SORT_STRING);

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $this->isReservedName($entry)) {
                    continue;
                }

                $path = $current . '/' . $entry;

                if (is_link($path)) {
                    continue;
                }

                if (is_dir($path)) {
                    if ($recursive && $this->denyDirectory($path) === null) {
                        $stack[] = $path;
                    }

                    continue;
                }

                if (!is_file($path) || $this->denyCommon($path) !== null) {
                    continue;
                }

                $size = @filesize($path);
                $mtime = @filemtime($path);

                if ($size === false || $mtime === false) {
                    continue;
                }

                yield ['path' => $path, 'size' => (int) $size, 'mtime' => (int) $mtime];
            }
        }
    }

    /**
     * The sub-folders of $directory this manager may see, as site paths: what
     * the folder picker offers. Never includes a folder they could not offload.
     *
     * @return string[]
     */
    public function folders(string $directory): array
    {
        $directory = $this->normalize($directory);

        if ($this->denyDirectory($directory) !== null) {
            return [];
        }

        $this->preload($directory);

        $folders = [];

        foreach (@scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $this->isReservedName($entry)) {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path) && !is_link($path) && $this->denyDirectory($path) === null) {
                $folders[] = (string) Paths::relative($path);
            }
        }

        sort($folders, SORT_NATURAL | SORT_FLAG_CASE);

        return $folders;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Layers 1-3 for one path, with no filesystem probing. */
    private function denyCommon(string $absolute): ?string
    {
        if (!$this->principal->active) {
            return 'inactive';
        }

        $root = rtrim($this->principal->root, '/');

        if (!FileManagerAccess::isWithin($root, $absolute)) {
            return 'outside_root';
        }

        foreach ($this->protectedPaths() as $protected) {
            if (FileManagerAccess::isWithin($protected, $absolute)) {
                return 'protected';
            }
        }

        if (!$this->principal->can('file_manager') && !$this->inOpenedFolder($absolute)) {
            return 'no_file_manager';
        }

        $below = $this->belowRoot($absolute);

        if ($below !== null && $this->principal->isRestrictedByGroups()) {
            $key = FileManagerAccess::aclKey($this->aclRoot(), $root, $below);

            if ($key !== null && !FileManagerAccess::isAccessible($key, $this->principal->documentGroups(), $this->restrictionsFor($key))) {
                return 'restricted';
            }
        }

        return null;
    }

    /** Folders this manager is locked out of by permission (layer 2). @return string[] */
    private function protectedPaths(): array
    {
        if ($this->protected !== null) {
            return $this->protected;
        }

        $paths = [];

        foreach (self::BY_PERMISSION as $permission => $folders) {
            if (!$this->principal->can($permission)) {
                foreach ($folders as $folder) {
                    $paths[] = Paths::absolute($folder);
                }
            }
        }

        if (!$this->mayRunCode()) {
            foreach ($this->codeFolders() as $folder) {
                $paths[] = $folder;
            }
        }

        return $this->protected = array_values(array_unique(array_map(
            static fn ($path) => rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/'),
            $paths
        )));
    }

    /** Folders a specific permission opens to a manager who lacks `file_manager`. @return string[] */
    private function openedFolders(): array
    {
        if ($this->opened !== null) {
            return $this->opened;
        }

        $opened = [];

        foreach (self::BY_PERMISSION as $permission => $folders) {
            if ($this->principal->can($permission)) {
                foreach ($folders as $folder) {
                    $opened[] = Paths::absolute($folder);
                }
            }
        }

        return $this->opened = $opened;
    }

    private function inOpenedFolder(string $absolute): bool
    {
        foreach ($this->openedFolders() as $folder) {
            if (FileManagerAccess::isWithin($folder, $absolute)) {
                return true;
            }
        }

        return false;
    }

    private function mayRunCode(): bool
    {
        foreach (self::CODE_PERMISSIONS as $permission) {
            if ($this->principal->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** `core/`, `manager/` and the template view paths: code lives there. @return string[] */
    private function codeFolders(): array
    {
        $folders = [
            defined('EVO_MANAGER_PATH') ? EVO_MANAGER_PATH : Paths::absolute('manager'),
            defined('EVO_CORE_PATH') ? EVO_CORE_PATH : Paths::absolute('core'),
        ];

        $views = function_exists('fileManagerTemplateViewPaths')
            ? fileManagerTemplateViewPaths()
            : [Paths::absolute('views')];

        return array_merge($folders, $views);
    }

    private function aclRoot(): string
    {
        return FileManagerAccess::aclRoot();
    }

    private function belowRoot(string $absolute): ?string
    {
        $root = rtrim($this->principal->root, '/');

        if (!FileManagerAccess::isWithin($root, $absolute)) {
            return null;
        }

        return trim(substr($absolute, strlen($root)), '/');
    }

    private function restrictionsFor(string $key): array
    {
        // Inside a subtree that was loaded whole, no row means no restriction:
        // asking the database again per file would turn a walk into N queries.
        foreach ($this->preloaded as $loaded) {
            if ($loaded === '' || FileManagerAccess::isWithin($loaded, $key)) {
                return $this->restrictions;
            }
        }

        if (!array_key_exists($key, $this->restrictions)) {
            $this->restrictions += FileManagerAccess::loadRestrictions([$key]);
            $this->restrictions[$key] ??= [];
        }

        return $this->restrictions;
    }

    /**
     * Load the file_groups of a whole subtree in one query, so a walk of
     * hundreds of thousands of files costs one database round-trip, not one per
     * folder.
     */
    private function preload(string $directory): void
    {
        if (!$this->principal->isRestrictedByGroups()) {
            return;
        }

        $below = $this->belowRoot($directory);
        $key = $below === null ? null : FileManagerAccess::aclKey($this->aclRoot(), rtrim($this->principal->root, '/'), $below);

        if ($key === null) {
            return;
        }

        foreach (FileManagerAccess::loadSubtreeRestrictions($key) as $path => $groups) {
            $this->restrictions[$path] = $groups;
        }

        $this->preloaded[] = $key;
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function same(string $a, string $b): bool
    {
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    private function isReservedName(string $name): bool
    {
        if (function_exists('fileManagerIsReservedDeviceName')) {
            return (bool) fileManagerIsReservedDeviceName($name);
        }

        $stem = rtrim(explode('.', basename($name), 2)[0], ' ');

        return (bool) preg_match('/^(?:con|prn|aux|nul|conin\$|conout\$|(?:com|lpt)[0-9¹²³])$/iu', $stem);
    }
}
