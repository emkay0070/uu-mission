<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\MissionOutcome;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * MissionOutcomePolicy — authorization for spiritual outcome records.
 *
 * Recording outcomes is a site-level task (missionaries and site secretaries).
 * Follow-up management can be done at campaign or site scope.
 * Viewing outcomes is available to all authorized campaign/site users.
 *
 * `outcomes.record` counts as read access for listing because a missionary
 * must be able to see the outcomes recorded at their own site — the seeded
 * missionary role holds outcomes.record but not outcomes.view. This mirrors
 * AttendanceRegisterPolicy, where attendance.submit counts as view access.
 *
 * This does NOT widen visibility: the controller still narrows every listing
 * to the user's authorised (campaign, site) pairs, so a missionary sees only
 * their own site's outcomes and never a sibling site's.
 */
class MissionOutcomePolicy
{
    /** Codes that grant read access to outcome data. */
    private const VIEW_PERMISSIONS = ['outcomes.view', 'outcomes.record'];

    public function __construct(private AuthorizationService $auth) {}

    public function viewAny(User $user, Campaign $campaign): bool
    {
        // Site-aware admission to outcome listing. A user may list outcomes
        // when they hold a read permission in ANY scope that resolves into
        // this campaign — campaign-wide, or a site/team inside it. The
        // controller then narrows the rows to the authorised (campaign, site)
        // pairs, so sibling-site outcomes are never exposed.
        return $this->auth->hasAuthorisedScopeInCampaign($user, self::VIEW_PERMISSIONS, $campaign);
    }

    public function view(User $user, MissionOutcome $outcome): bool
    {
        $campaign = $outcome->campaign;
        $site     = $outcome->site;

        if ($this->auth->hasPermissionForCampaign($user, self::VIEW_PERMISSIONS, $campaign)) {
            return true;
        }

        return $site && $this->auth->hasPermissionForSite($user, self::VIEW_PERMISSIONS, $site);
    }

    /**
     * Recording an outcome is a site-level task, so this is only a coarse
     * gate: it confirms the user holds outcomes.record in AT LEAST one scope.
     *
     * The controller then narrows the decision to the specific site that was
     * supplied, via AuthorizationService::hasPermissionForSite(). This mirrors
     * AttendanceController, and lets a site-scoped missionary record outcomes
     * at their own site while a campaign-scoped user records across the
     * campaign — without ever widening either scope.
     */
    public function create(User $user): bool
    {
        return $this->auth->getAuthorisedScopes($user, 'outcomes.record') !== [];
    }

    /**
     * Assigning a follow-up requires outcomes.manage_followup.
     * Missionaries only have outcomes.record — they cannot reassign follow-ups.
     *
     * Like view(), this is resolved against BOTH the outcome's campaign and the
     * outcome's actual site: a campaign-scoped manager may assign anywhere in
     * the campaign, while a site-scoped coordinator may assign only for an
     * outcome recorded at their OWN site. This deliberately does not widen a
     * site grant into campaign-wide authority, and never lets one site's
     * coordinator touch a sibling site's outcome.
     */
    public function assignFollowUp(User $user, MissionOutcome $outcome): bool
    {
        return $this->canManageFollowUp($user, $outcome);
    }

    /**
     * Completing a follow-up uses the same campaign-or-actual-site rule as
     * assignment, so a coordinator who could assign an outcome's follow-up can
     * also close it, and neither scope can reach outside its boundary.
     */
    public function completeFollowUp(User $user, MissionOutcome $outcome): bool
    {
        return $this->canManageFollowUp($user, $outcome);
    }

    /**
     * Shared follow-up authorization: campaign-wide authority first, then the
     * outcome's real site. The site branch is what closes the audit's P0 gap
     * for site coordinators; the campaign branch preserves existing behaviour.
     */
    private function canManageFollowUp(User $user, MissionOutcome $outcome): bool
    {
        $campaign = $outcome->campaign;
        $site     = $outcome->site;

        if ($this->auth->hasPermissionForCampaign($user, 'outcomes.manage_followup', $campaign)) {
            return true;
        }

        return $site && $this->auth->hasPermissionForSite($user, 'outcomes.manage_followup', $site);
    }
}
