<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * TeamPolicy — authorization for team and team-member management.
 *
 * Teams are always scoped to a campaign (and optionally a site within it).
 * We resolve the effective site when the team has a mission_site_id.
 */
class TeamPolicy
{
    public function __construct(private AuthorizationService $auth) {}

    public function viewAny(User $user, Campaign $campaign): bool
    {
        return $this->auth->canViewCampaign($user, $campaign);
    }

    public function view(User $user, Team $team): bool
    {
        return $this->auth->canViewCampaign($user, $team->campaign);
    }

    public function create(User $user, Campaign $campaign): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $campaign);
    }

    public function update(User $user, Team $team): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $team->campaign);
    }

    /**
     * Adding or removing members from a team.
     * Requires assignments.manage on the enclosing campaign.
     */
    public function manageMember(User $user, Team $team): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $team->campaign);
    }
}
