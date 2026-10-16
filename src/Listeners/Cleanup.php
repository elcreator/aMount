<?php

namespace Elcreator\aMount\Listeners;

use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\Mounts;
use Elcreator\aMount\Services\ServerConfig;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Housekeeping the schema cannot do: the tables carry no foreign keys (the core's
 * user and group tables differ between installs), so a deleted user or group is
 * cleaned up here. Objects already on a store are left alone — only aMount's own
 * records go.
 */
final class Cleanup
{
    /** `OnManagerDeleteUser` (params: userid). */
    public function user(array $params = []): void
    {
        $id = (int) ($params['userid'] ?? $params['id'] ?? 0);

        if ($id > 0) {
            $this->deleteUser($id);
        }
    }

    /** Remove the user's personal mounts (with their rules) and every rule they owned. */
    public function deleteUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            foreach (Mount::query()->where('owner_id', $userId)->get() as $mount) {
                Mounts::purge($mount);
            }

            $ids = Rule::query()->where('owner_id', $userId)->pluck('id')->all();
            $public = Rule::query()->whereIn('id', $ids)->where('visibility', Rule::PUBLIC)->exists();

            Run::query()->whereIn('rule_id', $ids)->delete();
            FileEntry::query()->whereIn('rule_id', $ids)->delete();
            Rule::query()->whereIn('id', $ids)->delete();

            if ($public) {
                ServerConfig::refresh();
            }
        } catch (Throwable) {
        }
    }

    /** A deleted user group no longer grants anything. */
    public function deleteGroup(int $groupId): void
    {
        if ($groupId > 0) {
            DB::table('amount_mount_groups')->where('user_group', $groupId)->delete();
        }
    }

    /** Drop grants whose group no longer exists: for events that do not say which group went. */
    public function sweepGroups(): void
    {
        try {
            $existing = DB::table('membergroup_names')->pluck('id')->all();
            DB::table('amount_mount_groups')->whereNotIn('user_group', $existing)->delete();
        } catch (Throwable) {
        }
    }
}
