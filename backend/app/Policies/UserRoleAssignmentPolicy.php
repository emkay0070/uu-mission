<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\RolePermissionVersion;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AuthorizationService;
use Illuminate\Support\Facades\DB;

/**
 * UserRoleAssignmentPolicy — authorization for role assignment management.
 *
 * Users can only assign roles within scopes they are authorised to manage.
 * Self-assignment escalation is prevented.
 */
class UserRoleAssignmentPolicy
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * View role assignments for a campaign.
     */
    public function viewAnyForCampaign(User $user, Campaign $campaign): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'assignments.view', $campaign);
    }

    /**
     * View role assignments for a site.
     */
    public function viewAnyForSite(User $user, MissionSite $site): bool
    {
        return $this->auth->hasPermissionForSite($user, 'assignments.view', $site);
    }

    /**
     * View role assignments for a team.
     */
    public function viewAnyForTeam(User $user, Team $team): bool
    {
        return $this->auth->hasPermissionForTeam($user, 'assignments.view', $team);
    }

    public function viewAnyForOrgUnit(User $user, OrgUnit $orgUnit): bool
    {
        return $this->auth->hasPermissionForOrgUnit($user, 'assignments.view', (int) $orgUnit->id);
    }

    /**
     * View a specific assignment.
     */
    public function view(User $user, UserRoleAssignment $assignment): bool
    {
        // Check based on the assignment's scope
        return match ($assignment->scope_type) {
            'global' => $this->auth->hasGlobalPermission($user, 'assignments.view'),
            'org_unit' => $assignment->scopedOrgUnit && $this->auth->hasPermissionForOrgUnit($user, 'assignments.view', (int) $assignment->scoped_org_unit_id),
            'campaign' => $assignment->scopedCampaign && $this->auth->hasPermissionForCampaign($user, 'assignments.view', $assignment->scopedCampaign),
            'site' => $assignment->scopedSite && $this->auth->hasPermissionForSite($user, 'assignments.view', $assignment->scopedSite, false),
            'team' => $assignment->scopedTeam && $this->auth->hasPermissionForTeam($user, 'assignments.view', $assignment->scopedTeam),
            default => false,
        };
    }

    /**
     * Create a role assignment.
     * Requires assignments.manage at the target scope.
     * Prevents self-assignment escalation.
     */
    public function create(User $user, array $validated): bool
    {
        $scopeType = $validated['scope_type'];

        // Prevent users from assigning themselves elevated roles. Both sides are
        // cast to integers: a request may deliver the target ID as a numeric
        // string, and a strict comparison would then treat "self" as a
        // different user and let the caller bypass this escalation guard.
        if ((int) $validated['user_id'] === (int) $user->id) {
            return false;
        }

        $canManage = match ($scopeType) {
            'global' => $this->auth->hasGlobalPermission($user, 'assignments.manage'),
            'org_unit' => isset($validated['scoped_org_unit_id']) && $this->auth->hasPermissionForOrgUnit($user, 'assignments.manage', (int) $validated['scoped_org_unit_id']),
            'campaign' => isset($validated['scoped_campaign_id'])
                          && $this->auth->hasPermissionForCampaign(
                              $user,
                              'assignments.manage',
                              Campaign::find($validated['scoped_campaign_id'])
                          ),
            'site' => isset($validated['scoped_site_id'])
                          && $this->auth->hasPermissionForSite(
                              $user,
                              'assignments.manage',
                              MissionSite::find($validated['scoped_site_id']),
                              false
                          ),
            'team' => isset($validated['scoped_team_id'])
                          && $this->auth->hasPermissionForTeam(
                              $user,
                              'assignments.manage',
                              Team::find($validated['scoped_team_id'])
                          ),
            default => false,
        };

        if (! $canManage) {
            return false;
        }

        $role = SystemRole::with('currentPermissionVersion.permissions')->find($validated['system_role_id']);
        if (! $role || ! $role->is_active || ! DB::table('role_scope_compatibilities')->where('system_role_id', $role->id)->where('scope_type', $scopeType)->exists()) {
            return false;
        }
        if (isset($validated['role_permission_version_id']) && (int) $validated['role_permission_version_id'] !== (int) $role->current_permission_version_id) {
            return false;
        }

        [$targetType, $targetId] = $this->auth->assignmentScope($validated);

        return $role->currentPermissionVersion?->permissions->every(
            fn (Permission $permission) => $this->auth->hasPermissionDelegation($permission->code, $targetType, $targetId)
        ) ?? false;
    }

    /**
     * Update an assignment (e.g., change dates or revoke).
     * Requires assignments.manage at the assignment's scope.
     */
    public function update(User $user, UserRoleAssignment $assignment): bool
    {
        // Prevent users from modifying their own assignments
        if ($assignment->user_id === $user->id) {
            return false;
        }

        return match ($assignment->scope_type) {
            'global' => $this->auth->hasGlobalPermission($user, 'assignments.manage'),
            'org_unit' => $assignment->scopedOrgUnit && $this->auth->hasPermissionForOrgUnit($user, 'assignments.manage', (int) $assignment->scoped_org_unit_id),
            'campaign' => $assignment->scopedCampaign && $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $assignment->scopedCampaign),
            'site' => $assignment->scopedSite && $this->auth->hasPermissionForSite($user, 'assignments.manage', $assignment->scopedSite, false),
            'team' => $assignment->scopedTeam && $this->auth->hasPermissionForTeam($user, 'assignments.manage', $assignment->scopedTeam),
            default => false,
        };
    }

    public function updateVersion(User $user, UserRoleAssignment $assignment, int $versionId): bool
    {
        if (! $this->update($user, $assignment)) {
            return false;
        }
        $version = RolePermissionVersion::with('permissions', 'role')->where('system_role_id', $assignment->system_role_id)->find($versionId);
        if (! $version || ! $version->published_at || (int) $version->role?->current_permission_version_id !== $versionId) {
            return false;
        }
        [$scopeType, $scopeId] = $this->auth->assignmentScope($assignment);

        return $version->permissions->every(fn (Permission $permission) => $this->auth->hasPermissionDelegation($permission->code, $scopeType, $scopeId));
    }

    /**
     * Delete/revoke an assignment.
     * Requires assignments.manage at the assignment's scope.
     */
    public function delete(User $user, UserRoleAssignment $assignment): bool
    {
        // Prevent users from revoking their own assignments
        if ($assignment->user_id === $user->id) {
            return false;
        }

        return $this->update($user, $assignment);
    }
}
