<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The permissions aMount is gated by, and their assignment to roles.
 *
 * Two keys, because the module serves two kinds of manager:
 *
 *   amount         use the module: connect one's own account (a personal
 *                  Google Drive, Dropbox ...) and offload the folders the
 *                  file manager shows that manager. Everything is bounded by
 *                  the same rules as the file manager: `filemanager_path`,
 *                  the per-permission protected folders and, with
 *                  `use_udperms` on, `file_groups`. Holding `amount` never
 *                  widens what one may see.
 *   amount_manage  administer: site-wide mounts (shared credentials, usable
 *                  by chosen user groups), other managers' rules, the server
 *                  configuration.
 *
 * Administrators (role 1) get both. Other roles get nothing by default: the
 * CMS has no sensible built-in answer to "which editor may copy site files to
 * an outside service", and a default of yes would be the wrong guess.
 *
 * Re-runnable: upserts everywhere, and a unique-key race is read as "someone
 * else got there first".
 */
return new class extends Migration {
    public $withinTransaction = false;

    private const GROUP = 'aMount';

    private const PERMISSIONS = [
        'amount' => ['name' => 'Use aMount', 'lang_key' => 'aMount::global.permission_use'],
        'amount_manage' => ['name' => 'Manage aMount', 'lang_key' => 'aMount::global.permission_manage'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('permissions_groups') || !Schema::hasTable('permissions')) {
            return;
        }

        $groupId = $this->group();

        foreach (self::PERMISSIONS as $key => $meta) {
            $attributes = $meta + ['group_id' => $groupId, 'disabled' => 0, 'updated_at' => now()];

            if (DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->where('key', $key)->update($attributes);
            } else {
                try {
                    DB::table('permissions')->insert($attributes + ['key' => $key, 'created_at' => now()]);
                } catch (QueryException $e) {
                    DB::table('permissions')->where('key', $key)->update($attributes);
                }
            }

            $this->grantToAdministrator($key);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission', array_keys(self::PERMISSIONS))->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
        }

        if (Schema::hasTable('permissions_groups') && Schema::hasTable('permissions')) {
            $group = DB::table('permissions_groups')->where('name', self::GROUP)->first();

            if ($group && !DB::table('permissions')->where('group_id', $group->id)->exists()) {
                DB::table('permissions_groups')->where('id', $group->id)->delete();
            }
        }
    }

    private function group(): int
    {
        $group = DB::table('permissions_groups')->where('name', self::GROUP)->first();

        if ($group) {
            return (int) $group->id;
        }

        try {
            return (int) DB::table('permissions_groups')->insertGetId([
                'name' => self::GROUP,
                'lang_key' => 'aMount::global.permissions_group',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $group = DB::table('permissions_groups')->where('name', self::GROUP)->first();

            if ($group) {
                return (int) $group->id;
            }

            throw $e;
        }
    }

    private function grantToAdministrator(string $permission): void
    {
        if (!Schema::hasTable('role_permissions')) {
            return;
        }

        if (DB::table('role_permissions')->where('role_id', 1)->where('permission', $permission)->exists()) {
            return;
        }

        try {
            DB::table('role_permissions')->insert([
                'role_id' => 1,
                'permission' => $permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Already there: a concurrent run won the race.
        }
    }
};
