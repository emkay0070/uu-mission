<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FieldReportVersion — an immutable snapshot of a report at one submission.
 *
 * Rows are appended on each formal submit and are never updated or deleted.
 * `snapshot` carries the whole serialised report (including its preparation
 * metadata and the human-authored sections) so a version can be read back
 * without consulting any live table.
 *
 * `$timestamps = false` with an explicit `created_at` matches the rest of the
 * append-only history tables in this codebase (e.g. attendance_entries).
 */
class FieldReportVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'field_report_id', 'version_number', 'status', 'review_state',
        'snapshot', 'created_by_user_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function fieldReport(): BelongsTo
    {
        return $this->belongsTo(FieldReport::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
