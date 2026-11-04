<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * MissionSitePolicy — authorization for site-level actions.
 *
 * A site always exists within a campaign context.
 * Site-scoped users can only see/edit their specific site.
 * Campaign-scoped users can see/edit all sites in their campaign.
 */
class MissionSitePolicy
{
    public function __construct(private AuthorizationService $auth) {}

    public function viewAny(User $user, Campaign $campaign): bool
    {
        // Admission to a campaign's site listing is scope-based, matching
        // view() (canViewSite): a user may list the sites of any campaign they
        // hold an assignment inside — campaign-wide, or a site/team that
        // belongs to it. MissionSiteController::index() then narrows the rows
        // to the authorised sites, so a site-scoped user only ever sees their
        // own site rather than being denied the listing outright.
        return $this->auth->hasAuthorisedScopeInCampaign($user, null, $campaign);
    }

    public function view(User $user, MissionSite $site): bool
    {
        return $this->auth->canViewSite($user, $site);
    }

    public function create(User $user, Campaign $campaign): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'sites.create', $campaign);
    }

    public function update(User $user, MissionSite $site): bool
    {
        return $this->auth->hasPermissionForSite($user, 'sites.edit', $site)
            || $this->auth->hasPermissionForCampaign($user, 'sites.edit', $site->campaign);
    }

    public function delete(User $user, MissionSite $site): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'sites.create', $site->campaign);
    }
}
