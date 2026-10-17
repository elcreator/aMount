<?php

namespace Elcreator\aMount\Services;

use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Support\Paths;
use Throwable;

/**
 * The index of offloaded files, as one manager may see it.
 *
 * Names are information too: an entry is listed only while its rule belongs to
 * the actor (or the actor administers rules) *and* the rule's folder is still
 * visible to the actor in the file manager.
 */
final class Files
{
    public const PER_PAGE = 50;

    /** @var int[]|null per instance: a walk over every rule is not free */
    private ?array $visible = null;

    public function __construct(private readonly Actor $actor)
    {
    }

    /**
     * @param array{status?:string, visibility?:string, q?:string} $filters
     * @return array{data:array<int, array<string, mixed>>, page:int, per_page:int, total:int, pages:int}
     */
    public function entries(?Rule $rule = null, array $filters = [], int $page = 1): array
    {
        $page = max(1, $page);
        $ids = $this->visibleRuleIds();

        if ($rule !== null) {
            $ids = in_array((int) $rule->getKey(), $ids, true) ? [(int) $rule->getKey()] : [];
        }

        $query = FileEntry::query()->whereIn('rule_id', $ids);

        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (!empty($filters['visibility'])) {
            $query->where('visibility', (string) $filters['visibility']);
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $like = '%' . addcslashes(trim((string) $filters['q']), '%_\\') . '%';
            $query->where('local_path', 'like', $like);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, self::PER_PAGE)->get();

        // One query for the rule names of this page, not one per row.
        $names = Rule::query()->whereIn('id', $rows->pluck('rule_id')->unique()->all())->pluck('name', 'id');

        return [
            'data' => $rows->map(fn (FileEntry $e) => $this->present($e) + ['rule_name' => (string) ($names[$e->rule_id] ?? '')])->all(),
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'pages' => (int) max(1, ceil($total / self::PER_PAGE)),
        ];
    }

    /** @throws AmountException */
    public function find(int $id): FileEntry
    {
        $entry = FileEntry::query()->find($id);

        if ($entry === null || !in_array((int) $entry->rule_id, $this->visibleRuleIds(), true)) {
            throw AmountException::notFound(AmountException::text('aMount::global.what_file'));
        }

        return $entry;
    }

    /**
     * Delete the object on the store and forget the row. The local file is not touched.
     *
     * @throws AmountException
     */
    public function delete(FileEntry $entry): void
    {
        $entry = $this->find((int) $entry->getKey());
        $mount = $entry->mount;

        if ($mount === null || !(new Mounts($this->actor))->canUse($mount)) {
            throw AmountException::forbidden();
        }

        if ($entry->status === FileEntry::UPLOADED) {
            try {
                $fs = $mount->filesystem();

                if ($fs->fileExists($entry->remote_path)) {
                    $fs->delete($entry->remote_path);
                }
            } catch (Throwable $e) {
                throw new AmountException('delete_failed', Mounts::redact($e->getMessage(), $mount), 502);
            }
        }

        $entry->delete();
    }

    /** @return array<string, mixed> */
    public function present(FileEntry $entry): array
    {
        $public = $entry->visibility === Rule::PUBLIC && $entry->status === FileEntry::UPLOADED;

        return [
            'id' => (int) $entry->getKey(),
            'rule_id' => (int) $entry->rule_id,
            'local_path' => $entry->local_path,
            'remote_path' => $entry->remote_path,
            'size' => (int) $entry->size,
            'size_human' => Paths::bytes((int) $entry->size),
            'mtime' => (int) $entry->mtime,
            'visibility' => $entry->visibility,
            'status' => $entry->status,
            'error' => $entry->error,
            'local_removed' => (bool) $entry->local_removed,
            'uploaded_at' => $entry->uploaded_at?->toIso8601String(),
            'url' => $public ? '/' . $entry->local_path : null,
        ];
    }

    /**
     * The cheap number: what the index knows, for one rule, one mount or all of
     * the actor's rules. Bytes count uploaded entries only (a pending file is not
     * on the store yet). The real, store-side figure comes from a measuring run.
     *
     * @return array{bytes:int, files:int, by_status:array<string,int>}
     */
    public function indexTotals(?Rule $rule = null, ?Mount $mount = null): array
    {
        $ids = $this->visibleRuleIds();

        if ($rule !== null) {
            $ids = in_array((int) $rule->getKey(), $ids, true) ? [(int) $rule->getKey()] : [];
        }

        $query = FileEntry::query()->whereIn('rule_id', $ids);

        if ($mount !== null) {
            $query->where('mount_id', $mount->getKey());
        }

        $byStatus = (clone $query)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $uploaded = (clone $query)->where('status', FileEntry::UPLOADED);

        return [
            'bytes' => (int) $uploaded->sum('size'),
            'files' => (int) ($byStatus[FileEntry::UPLOADED] ?? 0),
            'by_status' => $byStatus,
        ];
    }

    /** @return int[] */
    private function visibleRuleIds(): array
    {
        if ($this->visible !== null) {
            return $this->visible;
        }

        if (!$this->actor->mayUse()) {
            return [];
        }

        $query = Rule::query();

        if (!$this->actor->mayManage()) {
            $query->where('owner_id', $this->actor->userId());
        }

        $scope = $this->actor->scope();
        $ids = [];

        foreach ($query->get(['id', 'local_path']) as $rule) {
            $absolute = Paths::absolute($rule->local_path);
            $denied = is_dir($absolute) ? $scope->denyDirectory($absolute) : $scope->denyFile($absolute);

            if ($denied === null) {
                $ids[] = (int) $rule->getKey();
            }
        }

        return $this->visible = $ids;
    }
}
