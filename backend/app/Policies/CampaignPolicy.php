<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * CampaignPolicy — authorization for campaign-level actions.
 *
 * Permission mapping:
 *   viewAny  → campaigns.view  (listing)
 *   view     → campaigns.view  (single record)
 *   create   → campaigns.create
 *   update   → campaigns.edit
 *   transition → campaigns.edit + campaign scope (leaders can't create new campaigns)
 *   delete   → campaigns.archive (we don't hard-delete campaigns)
 */
class CampaignPolicy
{
    public function __construct(private AuthorizationService $auth) {}

    public function viewAny(User $user): bool
    {
        // Any authenticated, active user can list campaigns
        // (results are filtered to their scope by the controller query)
        return true;
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $this->auth->canViewCampaign($user, $campaign);
    }

    /**
     * viewContext — the minimum campaign context needed to operate inside it.
     *
     * Deliberately distinct from view(): a user whose access is confined to a
     * site or team within the campaign (e.g. a site-scoped missionary) may
     * resolve the campaign's identity so their workspace can label it, but must
     * NOT receive the campaign's full contents. view() therefore stays strict
     * (campaign-wide visibility only) for consumers such as the objectives
     * listing, while CampaignController::show() uses this ability and shapes a
     * reduced payload for context-only access.
     */
    public function viewContext(User $user, Campaign $campaign): bool
    {
        return $this->auth->hasAuthorisedScopeInCampaign($user, null, $campaign);
    }

    public function create(User $user): bool
    {
        return $this->auth->hasGlobalPermission($user, 'campaigns.create')
            || $this->hasOrgUnitPermission($user, 'campaigns.create');
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'campaigns.edit', $campaign);
    }

    /**
     * Transitioning lifecycle status (planning→active→completed→archived).
     * Only campaign leaders or above can do this.
     */
    public function transition(User $user, Campaign $campaign): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'campaigns.edit', $campaign);
    }

    /**
     * Archive — equivalent to "delete" in Laravel's policy naming.
     * We never hard-delete campaigns; archiving is the end state.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'campaigns.archive', $campaign);
    }

    public function viewStats(User $user, Campaign $campaign): bool
    {
        return $this->auth->canViewCampaign($user, $campaign);
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    private function hasOrgUnitPermission(User $user, string $permission): bool
    {
        // Org-unit scoped users (e.g. union directors) can create campaigns
        // within their org hierarchy — the service checks hierarchy when the
        // campaign exists; for creation we check they have the permission at
        // any org_unit scope level.
        return $this->auth->hasAnyOrgUnitPermission($user, $permission);
    }
}
