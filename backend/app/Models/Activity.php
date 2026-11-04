<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $fillable = [
        'campaign_id', 'mission_site_id', 'activity_type_id', 'title', 'description',
        'status', 'scheduled_at', 'actual_started_at', 'actual_ended_at',
        'location_note', 'led_by_person_id', 'estimated_attendance', 'notes',
        'is_planned',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at'     => 'datetime',
            'actual_started_at'=> 'datetime',
            'actual_ended_at'  => 'datetime',
            'is_planned'       => 'boolean',
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

    public function activityType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ActivityType::class);
    }

    public function ledBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class, 'led_by_person_id');
    }

    public function attendanceRegisters(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AttendanceRegister::class);
    }

    /**
     * What this activity sets out to achieve. Ordered for display.
     *
     * Read alongside the activity wherever its purpose matters — most notably
     * when the activity's attendance register is opened, and when outcomes and
     * reports are prepared.
     */
    public function objectives(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ActivityObjective::class)->orderBy('sort_order');
    }

    public function participants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ActivityParticipant::class);
    }

    public function outcomes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MissionOutcome::class);
    }

    public function speakers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'activity_speakers')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    public function mediaAttachments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'attachable');
    }
}
