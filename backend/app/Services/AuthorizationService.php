<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\FieldReport;
use App\Models\MissionOutcome;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * AuthorizationService — resolves user scope and permission checks.
 *
 * This is the single source of truth for scoped authorization decisions.
 * Laravel Policies call into this service rather than duplicating logic.
 *
 * Scope resolution rules (from narrowest to widest):
 *   global    → applies to every resource on the platform
 *   org_unit  → applies to campaigns/sites whose lead_org_unit_id is a
 *               descendant-or-equal of the scoped org unit
 *   campaign  → applies to one specific campaign and all its sites
 *   site      → applies to one specific site only
 *
 * Permission checks require both:
 *   1. The user has an active role assignment covering the resource scope.
 *   2. That role has the required permission code in role_permissions.
 *
 * Per-request caching: role assignments are loaded once per user per
 * request (via the in-memory array cache) to avoid N+1 across policies.
 */
class AuthorizationService
{
    /** In-memory cache of loaded assignments keyed by user ID. */
    private array $assignmentCache = [];

    /**
     * Check if the user has a specific permission for a campaign.
     *
     * Accepts a single permission code or a list of codes; the check passes
     * if any of the supplied codes is granted.
     */
    public function hasPermissionForCampaign(User $user, string|array $permissions, Campaign $campaign): bool
    {
        return $this->resolveEffectiveAssignments($user, $campaign)
                    ->contains(fn ($a) => $this->assignmentGrantsPermission($a, $permissions));
    }

    /**
     * Check if the user has a specific permission for a site.
     *
     * Accepts a single permission code or a list of codes.
     *
     * A team-scoped assignment normally counts as covering the SITE that team
     * belongs to, so a team member can load their own site context. Pass
     * $includeTeamScopedAssignments = false when a decision must be driven by
     * the site's own officers only — for example report submission or the
     * reporting chain's consolidation steps, where a team must never inherit
     * the whole site's authority.
     */
    public function hasPermissionForSite(
        User $user,
        string|array $permissions,
        MissionSite $site,
        bool $includeTeamScopedAssignments = true
    ): bool {
        return $this->resolveEffectiveAssignments($user, $site->campaign, $site, null, $includeTeamScopedAssignments)
                    ->contains(fn ($a) => $this->assignmentGrantsPermission($a, $permissions));
    }

    /**
     * Check if the user has a specific permission for a team.
     *
     * Accepts a single permission code or a list of codes.
     */
    public function hasPermissionForTeam(User $user, string|array $permissions, Team $team): bool
    {
        return $this->resolveEffectiveAssignments($user, $team->campaign, $team->site, $team)
                    ->contains(fn ($a) => $this->assignmentGrantsPermission($a, $permissions));
    }

    /**
     * Check if the user has a global-level permission (platform admin actions).
     */
    public function hasGlobalPermission(User $user, string $permission): bool
    {
        return $this->getActiveAssignments($user)
            ->filter(fn ($a) => $a->scope_type === 'global')
            ->contains(fn ($a) => $this->assignmentGrantsPermission($a, $permission));
    }

    /**
     * Check if the user has a specific permission for an org unit.
     * This checks both direct assignment and hierarchical (parent org units).
     */
    public function hasPermissionForOrgUnit(User $user, string $permission, int $orgUnitId): bool
    {
        // Check for exact match. The scope id is cast to int because Eloquent
        // may hand back the raw database value as a string, and a strict
        // comparison against an int would silently deny valid access.
        $exactMatch = $this->getActiveAssignments($user)
            ->filter(fn ($a) => $a->scope_type === 'org_unit' && (int) $a->scoped_org_unit_id === $orgUnitId)
            ->contains(fn ($a) => $this->assignmentGrantsPermission($a, $permission));

        if ($exactMatch) {
            return true;
        }

        // Check if user has permission from a parent org unit
        $orgUnit = OrgUnit::find($orgUnitId);
        if (!$orgUnit) {
            return false;
        }

        // Walk up the hierarchy checking parent org units
        $current = $orgUnit;
        while ($current && $current->parent_id) {
            $parentId = (int) $current->parent_id;

            $parentMatch = $this->getActiveAssignments($user)
                ->filter(fn ($a) => $a->scope_type === 'org_unit' && (int) $a->scoped_org_unit_id === $parentId)
                ->contains(fn ($a) => $this->assignmentGrantsPermission($a, $permission));

            if ($parentMatch) {
                return true;
            }

            $current = OrgUnit::find($parentId);
        }

        return false;
    }

    /**
     * Check if the user can see a campaign at all (any scoping).
     */
    public function canViewCampaign(User $user, Campaign $campaign): bool
    {
        return $this->resolveEffectiveAssignments($user, $campaign)
                    ->isNotEmpty();
    }

    /**
     * Check if the user can see a site at all (any scoping).
     */
    public function canViewSite(User $user, MissionSite $site): bool
    {
        return $this->resolveEffectiveAssignments($user, $site->campaign, $site)
                    ->isNotEmpty();
    }

    /**
     * Check if the user can see a team at all (any scoping).
     */
    public function canViewTeam(User $user, Team $team): bool
    {
        return $this->resolveEffectiveAssignments($user, $team->campaign, $team->site, $team)
                    ->isNotEmpty();
    }

    // ── Effective capability reporting (UI affordances only) ──────────────────

    /**
     * Return the flat, de-duplicated set of permission codes the user holds
     * through ANY active role assignment, irrespective of scope.
     *
     * IMPORTANT — this is a union across scopes and is NOT a statement that
     * the user may perform the action everywhere. It exists purely so the
     * client can decide which navigation entries and controls to render.
     *
     * Backend resource access must ALWAYS be authorised against the actual
     * requested resource's scope via hasPermissionForCampaign()/Site()/Team()
     * /OrgUnit(). Never use this method (or the value it produces) to grant
     * access to a specific record.
     *
     * @return array<int, string>
     */
    public function getEffectivePermissions(User $user): array
    {
        return $this->getActiveAssignments($user)
            ->flatMap(fn (UserRoleAssignment $a) => $this->assignmentPermissionCodes($a))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Return the scopes the user effectively holds, one entry per active
     * role assignment, so the client can understand WHY a capability exists
     * without flattening scoped permissions into global ones.
     *
     * Each entry describes a single active assignment:
     *   scope_type, the relevant scope id(s), the role code, and expiry.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEffectiveScopes(User $user): array
    {
        return $this->getActiveAssignments($user)
            ->map(fn (UserRoleAssignment $a) => [
                'role'         => $a->role?->code,
                'role_label'   => $a->role?->label,
                'scope_type'   => $a->scope_type,
                'org_unit_id'  => $a->scoped_org_unit_id,
                'campaign_id'  => $a->scoped_campaign_id,
                'site_id'      => $a->scoped_site_id,
                'team_id'      => $a->scoped_team_id,
                'valid_from'   => $a->valid_from?->toDateString(),
                'valid_until'  => $a->valid_until?->toDateString(),
            ])
            ->values()
            ->all();
    }

    // ── List scoping (campaign/site granularity) ──────────────────────────────

    /**
     * Return the ids of every campaign led by the given org unit or by any of
     * its descendants.
     *
     * This is how an org_unit-scoped appointment is expanded into the concrete
     * campaigns it covers — nothing wider than the hierarchy allows.
     *
     * @return array<int, int>
     */
    public function campaignIdsForOrgUnit(int $orgUnitId): array
    {
        return Campaign::whereIn('lead_org_unit_id', $this->orgUnitSubtreeIds($orgUnitId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Resolve the user's authorised (campaign, site) scope pairs for one or
     * more permission codes, one entry per active assignment granting them.
     *
     * Each entry is: ['campaign_id' => int|null, 'site_id' => int|null]
     *
     *   campaign_id = null, site_id = null → global (no constraint)
     *   campaign_id = X,    site_id = null → every site within campaign X
     *   campaign_id = X,    site_id = Y    → only site Y within campaign X
     *
     * This expands org_unit, site and team assignments into concrete ids so
     * that LIST queries express exactly the same scope semantics as the
     * single-resource checks in hasPermissionForSite()/Campaign(). It never
     * widens a scope: a site assignment yields a single (campaign, site) pair,
     * and a team assignment yields the site that team belongs to.
     *
     * Note: a campaign-wide team (no mission_site) contributes no list scope,
     * because it cannot be expressed as a campaign/site pair without granting
     * broader access than the team check itself allows.
     *
     * Passing null for $permissions resolves the pairs for EVERY active
     * assignment, regardless of the permissions its role carries. This mirrors
     * the permission-agnostic visibility rules in canViewCampaign() and
     * canViewSite() (which return true on the basis of scope alone) and is used
     * by listings whose admission is scope-based rather than capability-based,
     * such as the mission-site listing. An explicit permission code is
     * preferred everywhere else, so that listings stay capability-driven.
     *
     * @param  string|array<int, string>|null  $permissions
     * @param  bool  $includeTeamScopedAssignments  When false, team-scoped
     *         assignments contribute NOTHING — they are not expanded into the
     *         site they sit in. Use this for listings where a team must see
     *         only its own record and never the wider site (e.g. the reports
     *         listing, which narrows teams separately via teamIdsWithPermission()).
     * @return array<int, array{campaign_id: int|null, site_id: int|null}>
     */
    public function getAuthorisedScopes(
        User $user,
        string|array|null $permissions = null,
        bool $includeTeamScopedAssignments = true
    ): array {
        $codes  = $permissions === null ? null : (array) $permissions;
        $scopes = [];

        foreach ($this->getActiveAssignments($user) as $assignment) {
            if ($codes !== null && ! $this->assignmentGrantsPermission($assignment, $codes)) {
                continue;
            }

            switch ($assignment->scope_type) {
                case 'global':
                    $scopes[] = ['campaign_id' => null, 'site_id' => null];
                    break;

                case 'org_unit':
                    if ($assignment->scoped_org_unit_id === null) {
                        break;
                    }
                    foreach ($this->campaignIdsForOrgUnit((int) $assignment->scoped_org_unit_id) as $campaignId) {
                        $scopes[] = ['campaign_id' => $campaignId, 'site_id' => null];
                    }
                    break;

                case 'campaign':
                    if ($assignment->scoped_campaign_id !== null) {
                        $scopes[] = ['campaign_id' => (int) $assignment->scoped_campaign_id, 'site_id' => null];
                    }
                    break;

                case 'site':
                    $site = $assignment->scoped_site_id !== null
                        ? MissionSite::find($assignment->scoped_site_id)
                        : null;
                    if ($site) {
                        $scopes[] = ['campaign_id' => (int) $site->campaign_id, 'site_id' => (int) $site->id];
                    }
                    break;

                case 'team':
                    if (! $includeTeamScopedAssignments) {
                        break;
                    }
                    $team = $assignment->scoped_team_id !== null
                        ? Team::find($assignment->scoped_team_id)
                        : null;
                    if ($team && $team->mission_site_id !== null) {
                        $scopes[] = ['campaign_id' => (int) $team->campaign_id, 'site_id' => (int) $team->mission_site_id];
                    }
                    break;
            }
        }

        // De-duplicate identical (campaign, site) pairs.
        $unique = [];
        foreach ($scopes as $scope) {
            $unique[$scope['campaign_id'] . ':' . $scope['site_id']] = $scope;
        }

        return array_values($unique);
    }

    /**
     * Return the ids of every team the user holds in an active team-scoped
     * assignment whose role grants any of the given permission codes.
     *
     * This is the narrow counterpart to getAuthorisedScopes(): where that
     * method expands a team into the SITE it sits in (broad enough to load
     * site context), this returns the exact team ids so a listing can admit
     * only the user's own team's record — never a sibling team's, and never
     * the whole site.
     *
     * Used by the reports listing to honour the narrow `report.view_team`
     * visibility of a developing team report without granting broad official
     * report access.
     *
     * @param  string|array<int, string>  $permissions
     * @return array<int, int>
     */
    public function teamIdsWithPermission(User $user, string|array $permissions): array
    {
        $teamIds = [];

        foreach ($this->getActiveAssignments($user) as $assignment) {
            if ($assignment->scope_type !== 'team' || $assignment->scoped_team_id === null) {
                continue;
            }

            if ($this->assignmentGrantsPermission($assignment, $permissions)) {
                $teamIds[] = (int) $assignment->scoped_team_id;
            }
        }

        return array_values(array_unique($teamIds));
    }

    /**
     * Determine whether the user's authorised scopes for the given permission
     * include the given campaign AT ALL — either campaign-wide (global,
     * org_unit or campaign scope), or through a site/team assignment that sits
     * inside the campaign.
     *
     * This is the LIST-admission counterpart to scopeQuery(): a policy uses it
     * to decide whether to admit a listing request for a campaign, and the
     * controller then narrows the returned rows with scopeQuery(). That pairing
     * is what lets a site-scoped user list their own site's data without ever
     * seeing a sibling site's, while campaign-scoped users keep the campaign.
     *
     * Passing null for $permissions matches on scope alone (any active
     * assignment), mirroring canViewCampaign()/canViewSite().
     *
     * @param  string|array<int, string>|null  $permissions
     */
    public function hasAuthorisedScopeInCampaign(User $user, string|array|null $permissions, Campaign $campaign): bool
    {
        foreach ($this->getAuthorisedScopes($user, $permissions) as $scope) {
            // A global scope (no campaign and no site constraint) covers every
            // campaign on the platform.
            if ($scope['campaign_id'] === null && $scope['site_id'] === null) {
                return true;
            }

            if ((int) $scope['campaign_id'] === (int) $campaign->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Constrain an Eloquent query to a set of authorised scope pairs produced
     * by getAuthorisedScopes().
     *
     * The column names are configurable so that tables which do not carry a
     * site column (e.g. outreach_contacts) can still be scoped by campaign,
     * and tables with a differently-named site column can be scoped precisely.
     *
     * An empty scope list produces an unsatisfiable query (no access) rather
     * than an unconstrained one (full access) — failing closed.
     *
     * @param  Builder  $query
     * @param  array<int, array{campaign_id: int|null, site_id: int|null}>  $scopes
     * @param  string  $campaignColumn
     * @param  string|null  $siteColumn
     * @return Builder
     */
    public function scopeQuery(
        Builder $query,
        array $scopes,
        string $campaignColumn = 'campaign_id',
        ?string $siteColumn = null
    ): Builder {
        if ($scopes === []) {
            return $query->whereRaw('1 = 0');
        }

        // A global scope grants access to everything — no constraint needed.
        foreach ($scopes as $scope) {
            if ($scope['campaign_id'] === null && $scope['site_id'] === null) {
                return $query;
            }
        }

        return $query->where(function (Builder $outer) use ($scopes, $campaignColumn, $siteColumn) {
            foreach ($scopes as $scope) {
                $outer->orWhere(function (Builder $inner) use ($scope, $campaignColumn, $siteColumn) {
                    if ($scope['campaign_id'] !== null) {
                        $inner->where($campaignColumn, $scope['campaign_id']);
                    }
                    if ($scope['site_id'] !== null && $siteColumn !== null) {
                        $inner->where($siteColumn, $scope['site_id']);
                    }
                });
            }
        });
    }

    // ── Core scope resolution ──────────────────────────────────────────────────

    /**
     * Return all active assignments that grant any access to the given campaign
     * (and optionally a specific site and/or team within it).
     *
     * An assignment covers a campaign if:
     *   - scope_type = 'global'   → always covers everything
     *   - scope_type = 'org_unit' → covers campaigns whose lead_org_unit is
     *                               within the hierarchy of scoped_org_unit_id
     *   - scope_type = 'campaign' → covers exactly scoped_campaign_id
     *   - scope_type = 'site'     → covers exactly scoped_site_id (only when
     *                               a $site argument is also provided)
     *   - scope_type = 'team'     → covers exactly scoped_team_id (only when
     *                               a $team argument is also provided)
     */
    private function resolveEffectiveAssignments(
        User $user,
        Campaign $campaign,
        ?MissionSite $site = null,
        ?Team $team = null,
        bool $includeTeamScopedAssignments = true
    ): Collection {
        $assignments = $this->getActiveAssignments($user);

        return $assignments->filter(function (UserRoleAssignment $a) use ($campaign, $site, $team, $includeTeamScopedAssignments) {
            return match ($a->scope_type) {
                'global'   => true,
                'org_unit' => $this->orgUnitCoversOrgUnit(
                                  $a->scoped_org_unit_id,
                                  $campaign->lead_org_unit_id
                              ),
                'campaign' => (int) $a->scoped_campaign_id === (int) $campaign->id
                              && ($site === null || (int) $campaign->id === (int) $site->campaign_id),
                'site'     => $site !== null
                              && (int) $a->scoped_site_id === (int) $site->id,
                // A team-scoped role covers exactly the team it was granted for.
                // It also covers the SITE that team belongs to (so a team member
                // can load their own site context), but it must NOT match a
                // sibling team in the same site. The conditions below are
                // mutually exclusive: the site arm only applies when no specific
                // team was requested, which prevents sibling-team leakage.
                // $includeTeamScopedAssignments = false removes the site arm
                // entirely so that a team never inherits the site's authority.
                'team'     => $includeTeamScopedAssignments && $a->scoped_team_id !== null && (
                                  ($team !== null && (int) $a->scoped_team_id === (int) $team->id)
                                  || ($team === null && $site !== null && Team::where('id', $a->scoped_team_id)->where('mission_site_id', $site->id)->exists())
                              ),
                default    => false,
            };
        });
    }

    /**
     * Load and cache the user's active role assignments (with their role+permissions).
     */
    private function getActiveAssignments(User $user): Collection
    {
        if (! isset($this->assignmentCache[$user->id])) {
            $today = now()->toDateString();

            $this->assignmentCache[$user->id] = UserRoleAssignment::with(['role.permissions'])
                ->where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
                ->get();
        }

        return $this->assignmentCache[$user->id];
    }

    /**
     * Check whether an assignment's role grants any of the given permissions.
     *
     * @param  string|array<int, string>  $permissions
     */
    private function assignmentGrantsPermission(UserRoleAssignment $assignment, string|array $permissions): bool
    {
        if (! $assignment->relationLoaded('role') || ! $assignment->role) {
            return false;
        }

        if (! $assignment->role->relationLoaded('permissions')) {
            return false;
        }

        $codes = (array) $permissions;

        return $assignment->role->permissions->contains(fn ($p) => in_array($p->code, $codes, true));
    }

    /**
     * Return the permission codes granted by a single assignment's role.
     * Returns an empty list when the role or its permissions are not loaded.
     *
     * @return array<int, string>
     */
    private function assignmentPermissionCodes(UserRoleAssignment $assignment): array
    {
        if (! $assignment->relationLoaded('role') || ! $assignment->role) {
            return [];
        }

        if (! $assignment->role->relationLoaded('permissions')) {
            return [];
        }

        return $assignment->role->permissions->pluck('code')->all();
    }

    /**
     * Determine whether $ancestorId is an ancestor-or-equal of $targetId
     * in the org_units hierarchy.
     *
     * Uses a recursive CTE on PostgreSQL (via raw query) for efficiency.
     * Falls back to a PHP-side traversal during tests (SQLite compat).
     */
    private function orgUnitCoversOrgUnit(?int $ancestorId, ?int $targetId): bool
    {
        if ($ancestorId === null || $targetId === null) {
            return false;
        }

        if ($ancestorId === $targetId) {
            return true;
        }

        // Walk ancestors of $targetId up until we reach $ancestorId or the root
        $current = OrgUnit::find($targetId);

        while ($current && $current->parent_id !== null) {
            if ((int) $current->parent_id === $ancestorId) {
                return true;
            }
            $current = OrgUnit::find($current->parent_id);
        }

        return false;
    }

    /**
     * Return the id of $rootId plus every descendant org unit id.
     *
     * Used to expand an org_unit-scoped assignment into the campaigns it
     * covers: a campaign is covered when its lead_org_unit_id is the scoped
     * org unit itself or any org unit beneath it.
     *
     * @return array<int, int>
     */
    private function orgUnitSubtreeIds(int $rootId): array
    {
        $ids      = [$rootId];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $children = OrgUnit::whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->diff($ids)
                ->values()
                ->all();

            if ($children === []) {
                break;
            }

            $ids      = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    /**
     * Flush the per-request assignment cache (useful in tests).
     */
    public function flushCache(): void
    {
        $this->assignmentCache = [];
    }
}
