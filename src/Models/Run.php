<?php

namespace Elcreator\aMount\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One execution of a rule.
 *
 * The queue (system tasks or sTask) only *carries* a run. Progress, the
 * heartbeat and cancellation live here, so the page reads one table whichever
 * queue is active, and a cancel works the same on both: sTask has no cancel of
 * its own.
 */
class Run extends Model
{
    public const QUEUED = 'queued';
    public const SCANNING = 'scanning';
    public const UPLOADING = 'uploading';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const ACTIVE = [self::QUEUED, self::SCANNING, self::UPLOADING];

    protected $table = 'amount_runs';

    protected $guarded = [];

    protected $casts = [
        'files_total' => 'integer',
        'files_done' => 'integer',
        'files_failed' => 'integer',
        'bytes_total' => 'integer',
        'bytes_done' => 'integer',
        'started_by' => 'integer',
        'task_id' => 'integer',
        'payload_json' => 'array',
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $run) {
            $run->uuid = $run->uuid ?: (string) Str::uuid();
        });
    }

    public function rule()
    {
        return $this->belongsTo(Rule::class, 'rule_id');
    }

    public function isDownload(): bool
    {
        return $this->direction === 'down';
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isFinished(): bool
    {
        return !$this->isActive();
    }

    public function percent(): int
    {
        if ($this->status === self::DONE) {
            return 100;
        }

        if ($this->bytes_total > 0) {
            return (int) min(99, floor($this->bytes_done * 100 / $this->bytes_total));
        }

        return $this->files_total > 0 ? (int) min(99, floor($this->files_done * 100 / $this->files_total)) : 0;
    }
}
