<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\CampaignAssignment;
use App\Models\MissionSite;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * CampaignAssignmentPolicy — authorization for missionary deployment.
 *
 * Listing and viewing assignments:
 *   - Any user who can view the campaign can see the assignment list.
 *   - Site-scoped users admit the list at their OWN site (see viewAny).
 *
 * Creating / updating / deleting assignments:
 *   - Requires assignments.manage on the campaign.
 *   - Site secretaries have assignments.view only, not assignments.manage.
 */
class CampaignAssignmentPolicy
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * Admitting an assignment listing for a campaign, optionally narrowed to
     * one site.
     *
     * When the caller names a site, admission is decided at THAT site, because
     * a site-scoped coordinator (or secretary) holds assignments.view only
     * inside their own site and never campaign-wide. Deciding on the campaign
     * alone would deny them outright — a site-scoped assignment never
     * satisfies hasPermissionForCampaign — which is the defect the site branch
     * removes. Omitting the site keeps the original campaign-wide decision, so
     * the narrower site admission never widens a campaign-scoped caller.
     */
    public function viewAny(User $user, Campaign $campaign, ?MissionSite $site = null): bool
    {
        if ($site !== null) {
            return $this->auth->hasPermissionForSite($user, 'assignments.view', $site);
        }

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
