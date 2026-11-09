<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AttendanceEntry — one attendee at one register occurrence.
 *
 * IDENTITY STATES (enforced by the chk_ae_identifier / chk_ae_anonymous
 * CHECK constraints, not merely by validation):
 *
 *   identified             person_id set
 *   named but unidentified person_id null, visitor_name set (no Person invented)
 *   anonymous              person_id null, visitor_name null, is_anonymous true
 *
 * is_walk_in is a HISTORICAL SNAPSHOT: the attendee was not on the planned
 * roster (activity_participants) when this row was written. It is never
 * recomputed from current roster membership.
 *
 * attendee_type is an OPTIONAL classification (missionary | visitor |
 * local_member). It is left null when classification is not known or not
 * relevant — it is never forced, and it is never inferred from walk-in status.
 *
 * entry_uuid is the client-supplied idempotency key, unique per row, so a
 * retried attendance submission updates rather than duplicates.
 */
class AttendanceEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'attendance_register_id', 'entry_uuid', 'attendee_type', 'person_id',
        'is_walk_in', 'is_anonymous',
        'visitor_name', 'visitor_phone', 'visitor_gender',
        'is_first_time', 'decision_made', 'notes',
        'linked_by_user_id', 'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_first_time' => 'boolean',
            'is_walk_in' => 'boolean',
            'is_anonymous' => 'boolean',
            'created_at' => 'datetime',
            'linked_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(AttendanceRegister::class, 'attendance_register_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    /**
     * Whether this row carries a permanent identity.
     */
    public function isIdentified(): bool
    {
        return $this->person_id !== null;
    }
}
