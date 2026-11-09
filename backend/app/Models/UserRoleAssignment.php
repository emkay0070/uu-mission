<?php

namespace App\Models;

use App\Services\AuthorizationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'role_permission_version_id', 'status', 'revoked_by_user_id', 'revoked_at', 'revocation_reason',
        'suspended_by_user_id', 'suspended_at', 'suspension_reason',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'revoked_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $clear = function (self $assignment): void {
            if (app()->bound(AuthorizationService::class)) {
                app(AuthorizationService::class)->clearUserCache($assignment->user);
            }
        };
        static::saved($clear);
        static::deleted($clear);
        static::creating(function (self $assignment): void {
            if (! $assignment->role_permission_version_id && $assignment->system_role_id) {
                $assignment->role_permission_version_id = SystemRole::whereKey($assignment->system_role_id)
                    ->value('current_permission_version_id');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(SystemRole::class, 'system_role_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function permissionVersion(): BelongsTo
    {
        return $this->belongsTo(RolePermissionVersion::class, 'role_permission_version_id');
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserRoleAssignmentOverride::class);
    }

    public function scopedOrgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'scoped_org_unit_id');
    }

    public function scopedCampaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'scoped_campaign_id');
    }

    public function scopedSite(): BelongsTo
    {
        return $this->belongsTo(MissionSite::class, 'scoped_site_id');
    }

    public function scopedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'scoped_team_id');
    }

    /** Check if this assignment is currently valid. */
    public function isActive(): bool
    {
        $today = now()->toDateString();
        $fromOk = $this->valid_from === null || $this->valid_from->toDateString() <= $today;
        $untilOk = $this->valid_until === null || $this->valid_until->toDateString() >= $today;

        return $this->status === 'active' && $this->role?->is_active === true && $fromOk && $untilOk;
    }
}
