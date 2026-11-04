<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * UserRoleAssignment — scope-safe RBAC assignment.
 *
 * Exactly one of scoped_org_unit_id, scoped_campaign_id, scoped_site_id, scoped_team_id
 * is non-null (for non-global scope). Enforced by DB CHECK constraint.
 */
class UserRoleAssignment extends Model
{
    protected $fillable = [
        'user_id', 'system_role_id', 'scope_type',
        'scoped_org_unit_id', 'scoped_campaign_id', 'scoped_site_id', 'scoped_team_id',
        'valid_from', 'valid_until', 'granted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'valid_from'  => 'date',
            'valid_until' => 'date',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SystemRole::class, 'system_role_id');
    }

    public function grantedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function scopedOrgUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'scoped_org_unit_id');
    }

    public function scopedCampaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'scoped_campaign_id');
    }

    public function scopedSite(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionSite::class, 'scoped_site_id');
    }

    public function scopedTeam(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Team::class, 'scoped_team_id');
    }

    /** Check if this assignment is currently valid. */
    public function isActive(): bool
    {
        $today = now()->toDateString();
        $fromOk  = $this->valid_from  === null || $this->valid_from->toDateString()  <= $today;
        $untilOk = $this->valid_until === null || $this->valid_until->toDateString() >= $today;
        return $fromOk && $untilOk;
    }
}
