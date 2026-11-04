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
     */
    public function assignFollowUp(User $user, MissionOutcome $outcome): bool
    {
        return $this->auth->hasPermissionForCampaign(
            $user,
            'outcomes.manage_followup',
            $outcome->campaign
        );
    }

    public function completeFollowUp(User $user, MissionOutcome $outcome): bool
    {
        return $this->auth->hasPermissionForCampaign(
            $user,
            'outcomes.manage_followup',
            $outcome->campaign
        );
    }
}
