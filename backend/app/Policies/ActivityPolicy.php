<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * ActivityPolicy — authorization for scheduled field activities.
 *
 * An activity always belongs to exactly one campaign and one mission site.
 * Every check therefore resolves the activity's real (campaign, site) position
 * through AuthorizationService so that a site coordinator at Site A cannot
 * read or edit Site B's activities.
 *
 * PERMISSION MAPPING (deliberately separate from the attendance codes):
 *   viewAny  → activities.view   scoped to the campaign being listed
 *   view     → activities.view
 *   create   → activities.create
 *   update   → activities.update
 *   delete   → activities.delete
 *
 * `attendance.submit` is NOT accepted here, even though a missionary must be
 * able to fill in a register opened for an activity. A missionary receives the
 * distinct read code `activities.view` for that purpose, so that the ability to
 * record attendance never silently implies the ability to author activities.
 */
class ActivityPolicy
{
    /** Codes that grant read access to an activity. */
    private const VIEW_PERMISSIONS = ['activities.view'];

    public function __construct(private AuthorizationService $auth) {}

    /**
     * Listing activities for a specific campaign.
     *
     * The controller constrains the returned rows to the caller's scopes; this
     * admission check only has to establish that the permission exists inside
     * the campaign being listed at all (campaign-wide or through one of its
     * sites/teams).
     */
    public function viewAny(User $user, ?Campaign $campaign = null): bool
    {
        if ($campaign === null) {
            return $this->auth->getAuthorisedScopes($user, self::VIEW_PERMISSIONS) !== [];
        }

        return $this->auth->hasAuthorisedScopeInCampaign($user, self::VIEW_PERMISSIONS, $campaign);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->canAccessActivity($user, $activity, self::VIEW_PERMISSIONS);
    }

    /**
     * Creating an activity requires the create permission in the campaign the
     * activity is being placed in.
     *
     * The site is checked when it is known, because a site-scoped coordinator
     * legitimately holds activities.create only at their OWN site. Checking the
     * campaign alone would either deny them outright (a site-scoped assignment
     * never satisfies hasPermissionForCampaign) or, if widened, let them author
     * activities at a sibling site. The controller resolves the real site
     * first and passes it here, so both boundaries are enforced.
     */
    public function create(User $user, ?Campaign $campaign = null, ?MissionSite $site = null): bool
    {
        if ($site !== null) {
            return $this->auth->hasPermissionForSite($user, 'activities.create', $site);
        }

        if ($campaign === null) {
            return $this->auth->getAuthorisedScopes($user, ['activities.create']) !== [];
        }

        return $this->auth->hasPermissionForCampaign($user, 'activities.create', $campaign);
    }

    public function update(User $user, Activity $activity): bool
    {
        return $this->canAccessActivity($user, $activity, ['activities.update']);
    }

    public function delete(User $user, Activity $activity): bool
    {
        return $this->canAccessActivity($user, $activity, ['activities.delete']);
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    /**
     * An activity is accessible when the user holds any of the given
     * permissions at the activity's site, or campaign-wide.
     *
     * @param  array<int, string>  $permissions
     */
    private function canAccessActivity(User $user, Activity $activity, array $permissions): bool
    {
        $site     = $activity->site;
        $campaign = $activity->campaign;

        if ($site !== null && $this->auth->hasPermissionForSite($user, $permissions, $site)) {
            return true;
        }

        return $campaign !== null && $this->auth->hasPermissionForCampaign($user, $permissions, $campaign);
    }
}
