<?php

namespace Elcreator\aMount\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * "Send this folder to that mount": the thing a manager configures.
 *
 * `owner_id` is the account whose file-manager visibility bounds the rule,
 * every time it runs. A rule never runs with more rights than its owner has at
 * that moment.
 */
class Rule extends Model
{
    public const MODE_COPY = 'copy';
    public const MODE_MOVE = 'move';

    public const PRIVATE = 'private';
    public const PUBLIC = 'public';

    protected $table = 'amount_rules';

    protected $guarded = [];

    protected $casts = [
        'recursive' => 'boolean',
        'mirror_deletes' => 'boolean',
        'retention_days' => 'integer',
        'usage_json' => 'array',
        'enabled' => 'boolean',
        'ephemeral' => 'boolean',
        'min_age' => 'integer',
        'min_size' => 'integer',
        'max_size' => 'integer',
        'interval_minutes' => 'integer',
        'owner_id' => 'integer',
        'mount_id' => 'integer',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $rule) {
            $rule->uuid = $rule->uuid ?: (string) Str::uuid();
        });
    }

    /** @return string[] */
    public function includes(): array
    {
        return self::patterns($this->include_json);
    }

    /** @return string[] */
    public function excludes(): array
    {
        return self::patterns($this->exclude_json);
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::PUBLIC;
    }

    public function isMove(): bool
    {
        return $this->mode === self::MODE_MOVE;
    }

    public function mount()
    {
        return $this->belongsTo(Mount::class, 'mount_id');
    }

    public function files()
    {
        return $this->hasMany(FileEntry::class, 'rule_id');
    }

    public function runs()
    {
        return $this->hasMany(Run::class, 'rule_id');
    }

    /** @return string[] */
    public static function patterns(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return array_values(array_filter(array_map(
            static fn ($p) => trim((string) $p),
            is_array($stored) ? $stored : []
        ), static fn ($p) => $p !== ''));
    }
}
