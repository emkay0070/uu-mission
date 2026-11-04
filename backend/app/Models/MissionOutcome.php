<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MissionOutcome extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'mission_site_id', 'outcome_type', 'outcome_date',
        'person_id', 'visitor_name', 'visitor_phone', 'visitor_gender', 'visitor_age',
        'activity_id', 'attendance_register_id',
        'follow_up_status', 'follow_up_assigned_to_person_id',
        'follow_up_due_date', 'follow_up_completed_date', 'follow_up_notes',
        'notes', 'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'outcome_date'             => 'date',
            'follow_up_due_date'       => 'date',
            'follow_up_completed_date' => 'date',
        ];
    }

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function site(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionSite::class, 'mission_site_id');
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function activity(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function attendanceRegister(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AttendanceRegister::class);
    }

    public function followUpAssignedTo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class, 'follow_up_assigned_to_person_id');
    }

    public function recordedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
