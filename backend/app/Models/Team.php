<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Team — a flexible operational group within a campaign or site.
 *
 * origin_org_unit_id (WHERE does the team come from?) and team_type_id
 * (WHAT does the team do?) are independent dimensions — both optional.
 *
 * mission_site_id = null → campaign-wide team.
 * mission_site_id = set  → site-specific team (composite FK enforced at DB).
 */
class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'mission_site_id', 'team_type_id',
        'origin_org_unit_id', 'name', 'description',
    ];

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function site(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionSite::class, 'mission_site_id');
    }

    public function teamType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TeamType::class);
    }

    public function originOrgUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'origin_org_unit_id');
    }

    public function members(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function isCampaignWide(): bool
    {
        return $this->mission_site_id === null;
    }

    public function mediaAttachments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'attachable');
    }
}
