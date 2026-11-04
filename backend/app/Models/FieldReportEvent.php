<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FieldReportEvent — one append-only entry in a report's audit history.
 *
 * Rows are written on every transition (submit, approve, return, forward,
 * escalate, consolidate, recover) and are never updated or deleted. A report's
 * whole journey — who acted, at which level, how late, and why — can therefore
 * be reconstructed without reading any other table.
 *
 * `$timestamps = false` with an explicit `created_at` matches the other
 * append-only history tables in this codebase (attendance_entries, versions).
 */
class FieldReportEvent extends Model
{
    public $timestamps = false;

    /** Event types written by the reporting workflow. */
    public const TYPE_SUBMITTED    = 'submitted';
    public const TYPE_APPROVED     = 'approved';
    public const TYPE_RETURNED     = 'returned';
    public const TYPE_FORWARDED    = 'forwarded';
    public const TYPE_ESCALATED    = 'escalated';
    public const TYPE_CONSOLIDATED = 'consolidated';
    public const TYPE_RECOVERED    = 'recovered';

    protected $fillable = [
        'field_report_id', 'event_type', 'from_state', 'to_state',
        'level', 'actor_user_id', 'notes', 'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function fieldReport(): BelongsTo
    {
        return $this->belongsTo(FieldReport::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
