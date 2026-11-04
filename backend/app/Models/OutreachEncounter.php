<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutreachEncounter extends Model
{
    use HasFactory;

    protected $fillable = [
        'person_id',
        'campaign_id',
        'mission_site_id',
        'encounter_method_id',
        'recorded_by_user_id',
        'encounter_date',
        'notes',
        'stage_before_encounter_id',
        'stage_after_encounter_id',
        'next_action',
        'follow_up_date',
        'assigned_follow_up_user_id',
        'completed_at',
        'completed_by_user_id',
        'completion_notes',
    ];

    protected function casts(): array
    {
        return [
            'encounter_date' => 'date',
            'follow_up_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function outreachContact(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OutreachContact::class, 'person_id');
    }

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function site(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionSite::class, 'mission_site_id');
    }

    public function method(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EncounterMethod::class, 'encounter_method_id');
    }

    public function recordedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function stageBefore(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EngagementStage::class, 'stage_before_encounter_id');
    }

    public function stageAfter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EngagementStage::class, 'stage_after_encounter_id');
    }

    public function assignedFollowUpUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_follow_up_user_id');
    }

    public function completedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
