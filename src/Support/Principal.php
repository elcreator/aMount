<?php

namespace Elcreator\aMount\Support;

use Illuminate\Support\Facades\DB;

/**
 * One manager, resolved from the database — never from `$_SESSION`.
 *
 * The same object answers for the person clicking in the manager and for the
 * worker running their upload at three in the morning. That is the point: a
 * rule that was allowed when it was saved is asked again, as the account stands
 * *now*, every time it runs. Role changed, permission revoked, account blocked,
 * `filemanager_path` narrowed: the next run notices.
 *
 * It rebuilds what the core keeps in the session after login
 * (`mgrRole`, `mgrPermissions`, `mgrDocgroups`) with the same queries, because
 * the core exposes them only as a side effect of logging somebody in.
 */
final class Principal
{
    /** @param string[] $permissions @param int[] $docGroups @param int[] $userGroups */
    private function __construct(
        public readonly int $id,
        public readonly int $role,
        public readonly bool $active,
        private readonly array $permissions,
        private readonly array $docGroups,
        private readonly array $userGroups,
        public readonly string $root,
        public readonly bool $udperms
    ) {
    }

    public static function forUser(int $userId): self
    {
        $attributes = $userId > 0
            ? DB::table('user_attributes')->where('internalKey', $userId)->first()
            : null;
        $exists = $attributes !== null && DB::table('users')->where('id', $userId)->exists();

        $role = $exists ? (int) $attributes->role : 0;
        $active = $exists && $role > 0 && !self::blocked($attributes);

        $permissions = $role > 0
            ? DB::table('role_permissions')->where('role_id', $role)->pluck('permission')->map(static fn ($p) => (string) $p)->all()
            : [];

        // Document groups reachable through the user's groups: the join UserLogin
        // uses to fill $_SESSION['mgrDocgroups'].
        $docGroups = [];
        $userGroups = [];

        if ($exists) {
            $userGroups = DB::table('member_groups')->where('member', $userId)
                ->pluck('user_group')->map(static fn ($id) => (int) $id)->unique()->values()->all();

            $docGroups = DB::table('member_groups')
                ->join('membergroup_access', 'membergroup_access.membergroup', '=', 'member_groups.user_group')
                ->where('member_groups.member', $userId)
                ->pluck('membergroup_access.documentgroup')
                ->map(static fn ($id) => (int) $id)->unique()->values()->all();
        }

        return new self(
            $userId,
            $role,
            $active,
            $permissions,
            $docGroups,
            $userGroups,
            self::resolveRoot($userId),
            (bool) evo()->getConfig('use_udperms', true)
        );
    }

    /** Role 1 is the super administrator: all permissions, exempt from file groups. */
    public function isAdministrator(): bool
    {
        return $this->active && $this->role === 1;
    }

    public function can(string $permission): bool
    {
        return $this->active && ($this->isAdministrator() || in_array($permission, $this->permissions, true));
    }

    /** May use the module at all. */
    public function mayUse(): bool
    {
        return $this->can('amount');
    }

    /** May administer: site-wide mounts, everybody's rules, server config. */
    public function mayManage(): bool
    {
        return $this->can('amount_manage');
    }

    /** @return int[] */
    public function documentGroups(): array
    {
        return $this->docGroups;
    }

    /** @return int[] the user groups (member_groups.user_group) the manager belongs to */
    public function userGroups(): array
    {
        return $this->userGroups;
    }

    /** With `use_udperms` on, everyone but the administrator is held to `file_groups`. */
    public function isRestrictedByGroups(): bool
    {
        return $this->udperms && !$this->isAdministrator();
    }

    /** Same account, same answers, for tests that need to vary one fact. */
    public function withPermissions(array $permissions): self
    {
        return new self($this->id, $this->role, $this->active, $permissions, $this->docGroups, $this->userGroups, $this->root, $this->udperms);
    }

    private static function blocked(object $attributes): bool
    {
        $now = time();

        return (int) ($attributes->blocked ?? 0) !== 0
            || (int) ($attributes->blockeduntil ?? 0) > $now
            || ((int) ($attributes->blockedafter ?? 0) > 0 && (int) $attributes->blockedafter < $now);
    }

    /**
     * The manager's file root: their own `filemanager_path`, else the site's,
     * else the site root. The stored value carries the CMS placeholder
     * `[(base_path)]`, expanded the way Core::getSettings() expands it.
     */
    private static function resolveRoot(int $userId): string
    {
        $path = '';

        if ($userId > 0) {
            $path = trim((string) DB::table('user_settings')
                ->where('user', $userId)->where('setting_name', 'filemanager_path')->value('setting_value'));
        }

        if ($path === '') {
            $path = trim((string) (evo()->getConfig('filemanager_path') ?? ''));
        }

        if ($path === '') {
            $path = defined('EVO_BASE_PATH') ? EVO_BASE_PATH : Paths::base();
        }

        if (defined('EVO_BASE_PATH')) {
            $path = str_replace('[(base_path)]', EVO_BASE_PATH, $path);
        }

        $path = rtrim(str_replace('\\', '/', $path), '/');
        $real = realpath($path);

        return $real === false ? $path : rtrim(str_replace('\\', '/', $real), '/');
    }
}
