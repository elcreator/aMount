<?php

namespace Elcreator\aMount\Models;

use Elcreator\aMount\Support\Paths;
use Illuminate\Database\Eloquent\Model;

/**
 * One local file as aMount knows it: what was sent where, and whether it is
 * still current. Also the lookup that lets a request for a file that is no
 * longer on disk find its remote copy.
 */
class FileEntry extends Model
{
    public const PENDING = 'pending';
    public const UPLOADING = 'uploading';
    public const UPLOADED = 'uploaded';
    public const FAILED = 'failed';
    /** Deleted from the store (retention, mirror, or "delete after download"); the row is the receipt. */
    public const EXPIRED = 'expired';

    protected $table = 'amount_files';

    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
        'mtime' => 'integer',
        'attempts' => 'integer',
        'local_removed' => 'boolean',
        'uploaded_at' => 'datetime',
        'seen_at' => 'datetime',
    ];

    public function rule()
    {
        return $this->belongsTo(Rule::class, 'rule_id');
    }

    public function mount()
    {
        return $this->belongsTo(Mount::class, 'mount_id');
    }

    public static function hashOf(string $sitePath): string
    {
        return Paths::hash($sitePath);
    }
}
