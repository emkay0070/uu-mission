<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\CampaignAssignment;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * CampaignAssignmentPolicy — authorization for missionary deployment.
 *
 * Listing and viewing assignments:
 *   - Any user who can view the campaign can see the assignment list.
 *   - Site-scoped users see all assignments (list is filtered in controller).
 *
 * Creating / updating / deleting assignments:
 *   - Requires assignments.manage on the campaign.
 *   - Site secretaries have assignments.view only, not assignments.manage.
 */
class CampaignAssignmentPolicy
{
    public function __construct(private AuthorizationService $auth) {}

    public function viewAny(User $user, Campaign $campaign): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'assignments.view', $campaign);
    }

    public function view(User $user, CampaignAssignment $assignment): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'assignments.view', $assignment->campaign);
    }

    public function create(User $user, Campaign $campaign): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $campaign);
    }

    public function update(User $user, CampaignAssignment $assignment): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $assignment->campaign);
    }

    public function delete(User $user, CampaignAssignment $assignment): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $assignment->campaign);
    }
}
