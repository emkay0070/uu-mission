<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AuthorizationService;

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

    /**
     * View a specific assignment.
     */
    public function view(User $user, UserRoleAssignment $assignment): bool
    {
        // Check based on the assignment's scope
        return match ($assignment->scope_type) {
            'global'   => $this->auth->hasGlobalPermission($user, 'assignments.view'),
            'org_unit' => $assignment->scopedOrgUnit && $this->auth->hasGlobalPermission($user, 'assignments.view'),
            'campaign' => $assignment->scopedCampaign && $this->auth->hasPermissionForCampaign($user, 'assignments.view', $assignment->scopedCampaign),
            'site'     => $assignment->scopedSite && $this->auth->hasPermissionForSite($user, 'assignments.view', $assignment->scopedSite),
            'team'     => $assignment->scopedTeam && $this->auth->hasPermissionForTeam($user, 'assignments.view', $assignment->scopedTeam),
            default    => false,
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

        // Prevent users from assigning themselves elevated roles
        if ($validated['user_id'] === $user->id) {
            // Allow self-assignment only if they already have equivalent or higher authority
            // For now, block all self-assignment to prevent escalation
            return false;
        }

        return match ($scopeType) {
            'global'   => $this->auth->hasGlobalPermission($user, 'assignments.manage'),
            'org_unit' => $this->auth->hasGlobalPermission($user, 'assignments.manage'),
            'campaign' => isset($validated['scoped_campaign_id'])
                          && $this->auth->hasPermissionForCampaign(
                              $user,
                              'assignments.manage',
                              Campaign::find($validated['scoped_campaign_id'])
                          ),
            'site'     => isset($validated['scoped_site_id'])
                          && $this->auth->hasPermissionForSite(
                              $user,
                              'assignments.manage',
                              MissionSite::find($validated['scoped_site_id'])
                          ),
            'team'     => isset($validated['scoped_team_id'])
                          && $this->auth->hasPermissionForTeam(
                              $user,
                              'assignments.manage',
                              Team::find($validated['scoped_team_id'])
                          ),
            default    => false,
        };
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
            'global'   => $this->auth->hasGlobalPermission($user, 'assignments.manage'),
            'org_unit' => $this->auth->hasGlobalPermission($user, 'assignments.manage'),
            'campaign' => $assignment->scopedCampaign && $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $assignment->scopedCampaign),
            'site'     => $assignment->scopedSite && $this->auth->hasPermissionForSite($user, 'assignments.manage', $assignment->scopedSite),
            'team'     => $assignment->scopedTeam && $this->auth->hasPermissionForTeam($user, 'assignments.manage', $assignment->scopedTeam),
            default    => false,
        };
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
