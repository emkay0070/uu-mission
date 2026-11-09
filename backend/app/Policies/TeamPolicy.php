<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * TeamPolicy — authorization for team and team-member management.
 *
 * Teams always belong to a campaign and may optionally be tied to one of its
 * mission sites. Every mutation resolves the team's real (campaign, site)
 * position through AuthorizationService, so a site-scoped coordinator can
 * manage the teams at their OWN site without gaining authority over the whole
 * campaign or over a sibling site.
 *
 * PERMISSION MAPPING:
 *   viewAny      → campaign visibility, or site visibility when a site is named
 *   view         → the team's real (campaign, site, team) visibility
 *   create       → assignments.manage (at the target site when known)
 *   update       → assignments.manage (at the team's site, else campaign-wide)
 *   manageMember → assignments.manage (at the team's site, else campaign-wide)
 */
class TeamPolicy
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * Admitting a team listing for a campaign, optionally narrowed to one site.
     *
     * When the caller names a site, admission is decided at THAT site, because
     * a site-scoped coordinator holds no campaign-wide authority yet must be
     * able to list the teams at their own site. Deciding on the campaign alone
     * would deny them outright (a site-scoped assignment never satisfies
     * canViewCampaign) — this is the defect the site branch removes. Omitting
     * the site keeps the original campaign-wide decision, so the narrower site
     * admission never widens what a campaign-scoped caller could already list.
     */
    public function viewAny(User $user, Campaign $campaign, ?MissionSite $site = null): bool
    {
        if ($site !== null) {
            return $this->auth->canViewSite($user, $site);
        }

        return $this->auth->canViewCampaign($user, $campaign);
    }

    /**
     * Viewing a single team is decided on the team's real position (campaign,
     * site and team), so a site-scoped coordinator can read the rosters of the
     * teams at their OWN site without gaining authority over a sibling site.
     */
    public function view(User $user, Team $team): bool
    {
        return $this->auth->canViewTeam($user, $team);
    }

    /**
     * Creating a team requires assignments.manage where the team will operate.
     *
     * The site is checked when it is known, because a site-scoped coordinator
     * legitimately holds assignments.manage only at their OWN site. Checking the
     * campaign alone would either deny them outright (a site-scoped assignment
     * never satisfies hasPermissionForCampaign) or, if widened, let them stand
     * up teams at a sibling site. The controller resolves the real site first
     * and passes it here, so both boundaries are enforced. A team with no site
     * (campaign-wide) still requires campaign-wide authority.
     */
    public function create(User $user, Campaign $campaign, ?MissionSite $site = null): bool
    {
        if ($site !== null) {
            return $this->auth->hasPermissionForSite($user, 'assignments.manage', $site);
        }

        return $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $campaign);
    }

    public function update(User $user, Team $team): bool
    {
        return $this->canManageTeam($user, $team);
    }

    /**
     * Adding or removing members from a team.
     */
    public function manageMember(User $user, Team $team): bool
    {
        return $this->canManageTeam($user, $team);
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    /**
     * A team is manageable when the user holds assignments.manage at the team's
     * site, or campaign-wide.
     */
    private function canManageTeam(User $user, Team $team): bool
    {
        $site = $team->site;

        if ($site !== null && $this->auth->hasPermissionForSite($user, 'assignments.manage', $site)) {
            return true;
        }

        return $team->campaign !== null
            && $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $team->campaign);
    }
}
