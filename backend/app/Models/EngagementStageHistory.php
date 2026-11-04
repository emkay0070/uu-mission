<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * EngagementStageHistory — tracks changes to a person's engagement stage over time.
 *
 * This preserves historical context even when a person's current stage changes
 * (e.g., a member becoming inactive, or someone declining further contact).
 */
class EngagementStageHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'engagement_stage_histories';

    protected $fillable = [
        'outreach_contact_id',
        'person_id',
        'from_stage_id',
        'to_stage_id',
        'changed_by_user_id',
        'notes',
        'changed_at',
        'outreach_encounter_id',
        'correction_reason',
        'is_correction',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function outreachContact(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OutreachContact::class);
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function changedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function fromStage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EngagementStage::class, 'from_stage_id');
    }

    public function toStage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EngagementStage::class, 'to_stage_id');
    }

    public function outreachEncounter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OutreachEncounter::class);
    }

    /**
     * Get the person through the outreach contact relationship.
     */
    public function personThroughContact(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }
}
