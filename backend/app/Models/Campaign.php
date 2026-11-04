<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Campaign — a bounded operational occurrence under a programme.
 *
 * Lifecycle: planning → active → completed → archived.
 * Completed campaigns are permanent historical records — status transitions
 * to 'archived' instead of deletion.
 *
 * The composite unique on (id, programme_id) allows mission_sites to hold
 * a composite FK that guarantees site → campaign → programme consistency.
 */
class Campaign extends Model
{
    use HasFactory;
    protected $fillable = [
        'programme_id', 'lead_org_unit_id', 'name', 'description',
        'status', 'start_date', 'end_date', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date'   => 'date',
        ];
    }

    public function programme(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function leadOrgUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'lead_org_unit_id');
    }

    public function sites(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MissionSite::class);
    }

    public function teams(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function objectives(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CampaignObjective::class);
    }

    public function assignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CampaignAssignment::class);
    }

    public function targetAreas(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(MissionArea::class, 'campaign_target_areas');
    }

    /**
     * Scheduled field activities that belong to this campaign.
     *
     * Activities own their campaign through `campaign_id`, which is the default
     * foreign key for this HasMany. It is also half of the composite FK
     * (mission_site_id, campaign_id) that keeps every activity's site inside its
     * own campaign, so listing through this relation can never cross campaigns.
     */
    public function activities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function fieldReports(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FieldReport::class);
    }

    public function outcomes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MissionOutcome::class);
    }

    public function newGroups(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NewGroup::class);
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function mediaAttachments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'attachable');
    }
}
