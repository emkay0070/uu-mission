<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * OutreachContact — outreach-specific journey tracking linked to Person.
 *
 * This model separates outreach engagement stages and journey information
 * from the permanent Person identity. A person may have an OutreachContact,
 * a MissionaryProfile, both, or neither.
 */
class OutreachContact extends Model
{
    protected $fillable = [
        'person_id',
        'engagement_stage_id',
        'village',
        'date_registered',
        'notes',
        'campaign_id',
        'first_encountered_campaign_id',
        'first_encountered_site_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'date_registered' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function engagementStage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EngagementStage::class);
    }

    public function outreachEncounters(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OutreachEncounter::class);
    }

    public function engagementStageHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EngagementStageHistory::class)->orderBy('changed_at', 'desc');
    }

    public function baptismRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BaptismRecord::class);
    }

    /**
     * Authoritative stage transition operation.
     *
     * Updates the outreach contact's engagement stage and creates a history record within a transaction.
     *
     * @param int $toStageId The new stage ID
     * @param int|null $changedByUserId The user making the change
     * @param int|null $outreachEncounterId The encounter that prompted this change (if applicable)
     * @param string|null $correctionReason Reason for correction (if this is a correction)
     * @param bool $isCorrection Whether this is an administrative correction
     * @return EngagementStageHistory The created history record
     */
    public function transitionEngagementStage(
        int $toStageId,
        ?int $changedByUserId = null,
        ?int $outreachEncounterId = null,
        ?string $correctionReason = null,
        bool $isCorrection = false
    ): EngagementStageHistory {
        return \DB::transaction(function () use ($toStageId, $changedByUserId, $outreachEncounterId, $correctionReason, $isCorrection) {
            $fromStageId = $this->engagement_stage_id;

            $this->engagement_stage_id = $toStageId;
            $this->save();

            return $this->engagementStageHistory()->create([
                'outreach_contact_id' => $this->id,
                'person_id' => $this->person_id, // Keep person_id for reference
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $toStageId,
                'changed_by_user_id' => $changedByUserId ?? auth()->id(),
                'changed_at' => now(),
                'outreach_encounter_id' => $outreachEncounterId,
                'correction_reason' => $correctionReason,
                'is_correction' => $isCorrection,
            ]);
        });
    }
}
