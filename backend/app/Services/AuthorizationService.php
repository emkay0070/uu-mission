<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\ResourceDistributionRequest;
use App\Models\RolePermissionVersion;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        return $this->effectivePermissionDecision($this->resolveEffectiveAssignments($user, $campaign), $permissions);
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
        return $this->effectivePermissionDecision(
            $this->resolveEffectiveAssignments($user, $site->campaign, $site, null, $includeTeamScopedAssignments),
            $permissions
        );
    }

    /**
     * Check if the user has a specific permission for a team.
     *
     * Accepts a single permission code or a list of codes.
     */
    public function hasPermissionForTeam(User $user, string|array $permissions, Team $team): bool
    {
        return $this->effectivePermissionDecision(
            $this->resolveEffectiveAssignments($user, $team->campaign, $team->site, $team),
            $permissions
        );
    }

    /**
     * Check if the user has a global-level permission (platform admin actions).
     */
    public function hasGlobalPermission(User $user, string $permission): bool
    {
        return $this->effectivePermissionDecision(
            $this->getActiveAssignments($user)->where('scope_type', 'global'),
            $permission
        );
    }

    /**
     * Check if the user has a specific permission for an org unit.
     * This checks both direct assignment and hierarchical (parent org units).
     */
    public function hasPermissionForOrgUnit(User $user, string $permission, int $orgUnitId): bool
    {
        // Collect the assignments scoped to this org unit plus those scoped to
        // any ancestor org unit, then decide once so a denial anywhere in the
        // applicable hierarchy wins over a broader grant.
        $matching = $this->getActiveAssignments($user)
            ->filter(fn (UserRoleAssignment $a) => $a->scope_type === 'org_unit'
                && $this->orgUnitCoversOrgUnit($a->scoped_org_unit_id !== null ? (int) $a->scoped_org_unit_id : null, $orgUnitId));

        return $this->effectivePermissionDecision($matching, $permission);
    }

    /**
     * Check whether the user holds a permission for AT LEAST ONE org-unit
     * scope they are assigned to, without widening to a specific org unit.
     *
     * Used by policies that only need "any org-unit authority" (for example
     * creating a new campaign), not permission over a concrete unit.
     */
    public function hasAnyOrgUnitPermission(User $user, string $permission): bool
    {
        $ids = $this->getActiveAssignments($user)
            ->where('scope_type', 'org_unit')
            ->pluck('scoped_org_unit_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $ids->contains(fn (int $id) => $this->hasPermissionForOrgUnit($user, $permission, $id));
    }

    /**
     * Resolve a permission at one assignment's scope.
     *
     * Used when the applicable scope was already determined from another
     * record (for example a resource's origin assignment), and the question
     * is whether THIS user's own authority covers that exact scope.
     */
    public function hasPermissionAtAssignmentScope(User $user, string $permission, string $scopeType, ?int $scopeId): bool
    {
        return match ($scopeType) {
            'global' => $this->hasGlobalPermission($user, $permission),
            'org_unit' => $scopeId !== null && $this->hasPermissionForOrgUnit($user, $permission, $scopeId),
            'campaign' => ($campaign = $scopeId ? Campaign::find($scopeId) : null) !== null
                && $this->hasPermissionForCampaign($user, $permission, $campaign),
            'site' => ($site = $scopeId ? MissionSite::find($scopeId) : null) !== null
                && $this->hasPermissionForSite($user, $permission, $site, false),
            'team' => ($team = $scopeId ? Team::find($scopeId) : null) !== null
                && $this->hasPermissionForTeam($user, $permission, $team),
            default => false,
        };
    }

    /**
     * Return the active assignments whose effective permission set explicitly
     * grants the given permission (overrides honoured).
     *
     * Callers use this to walk contributor origin scopes one assignment at a
     * time, pairing it with hasPermissionAtAssignmentScope().
     */
    public function getActiveAssignmentsGranting(User $user, string $permission): Collection
    {
        return $this->getActiveAssignments($user)
            ->filter(fn (UserRoleAssignment $assignment) => $this->permissionEffect($assignment, $permission) === 'grant')
            ->values();
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
     *   scope_type, the relevant scope id(s), the role code, expiry, and the
     *   permission codes this SPECIFIC assignment carries (overrides applied).
     *
     * The per-assignment `permissions` list exists so the client can ask a
     * scope-aware question ("may I do X *in this site*?") without flattening
     * the union. It remains a UI affordance: the backend re-authorises every
     * request against the real resource scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEffectiveScopes(User $user): array
    {
        return $this->getActiveAssignments($user)
            ->map(fn (UserRoleAssignment $a) => [
                'role' => $a->role?->code,
                'role_label' => $a->role?->label,
                'scope_type' => $a->scope_type,
                'org_unit_id' => $a->scoped_org_unit_id,
                'campaign_id' => $a->scoped_campaign_id,
                'site_id' => $a->scoped_site_id,
                'team_id' => $a->scoped_team_id,
                'valid_from' => $a->valid_from?->toDateString(),
                'valid_until' => $a->valid_until?->toDateString(),
                'permissions' => $this->assignmentPermissionCodes($a),
            ])
            ->values()
            ->all();
    }

    /**
     * The user's active role assignments, exactly as the authorization engine
     * resolves them (status, role is_active, validity window, revocation).
     *
     * Exposed read-only so callers that must reason about authority — such as
     * workspace resolution — use the SAME set of assignments every permission
     * decision uses. It grants nothing by itself.
     *
     * @return Collection<int, UserRoleAssignment>
     */
    public function activeAssignmentsFor(User $user): Collection
    {
        return $this->getActiveAssignments($user);
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
     * Deny overrides narrow the result: an exact denial removes its pair, and
     * a site-level denial carves that site out of any broader pair (recorded
     * as except_site_ids, honoured by scopeQuery()).
     *
     * @param  string|array<int, string>|null  $permissions
     * @param  bool  $includeTeamScopedAssignments  When false, team-scoped
     *                                              assignments contribute NOTHING — they are not expanded into the
     *                                              site they sit in. Use this for listings where a team must see
     *                                              only its own record and never the wider site (e.g. the reports
     *                                              listing, which narrows teams separately via teamIdsWithPermission()).
     * @return array<int, array{campaign_id: int|null, site_id: int|null, except_site_ids?: array<int, int>}>
     */
    public function getAuthorisedScopes(
        User $user,
        string|array|null $permissions = null,
        bool $includeTeamScopedAssignments = true
    ): array {
        $codes = $permissions === null ? null : (array) $permissions;
        $scopes = [];

        $expand = function (UserRoleAssignment $assignment, bool $includeTeamScopedAssignments, array &$out): void {
            switch ($assignment->scope_type) {
                case 'global':
                    $out[] = ['campaign_id' => null, 'site_id' => null];
                    break;

                case 'org_unit':
                    if ($assignment->scoped_org_unit_id === null) {
                        break;
                    }
                    foreach ($this->campaignIdsForOrgUnit((int) $assignment->scoped_org_unit_id) as $campaignId) {
                        $out[] = ['campaign_id' => $campaignId, 'site_id' => null];
                    }
                    break;

                case 'campaign':
                    if ($assignment->scoped_campaign_id !== null) {
                        $out[] = ['campaign_id' => (int) $assignment->scoped_campaign_id, 'site_id' => null];
                    }
                    break;

                case 'site':
                    $site = $assignment->scoped_site_id !== null
                        ? MissionSite::find($assignment->scoped_site_id)
                        : null;
                    if ($site) {
                        $out[] = ['campaign_id' => (int) $site->campaign_id, 'site_id' => (int) $site->id];
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
                        $out[] = ['campaign_id' => (int) $team->campaign_id, 'site_id' => (int) $team->mission_site_id];
                    }
                    break;
            }
        };

        if ($codes === null) {
            foreach ($this->getActiveAssignments($user) as $assignment) {
                $expand($assignment, $includeTeamScopedAssignments, $scopes);
            }
        } else {
            // Resolve per permission code so a denial for one code never
            // narrows scopes that were granted for a different code in the
            // same request.
            $result = [];
            foreach ($codes as $code) {
                $codeScopes = [];
                $denials = [];

                foreach ($this->getActiveAssignments($user) as $assignment) {
                    $effect = $this->permissionEffect($assignment, $code);
                    if ($effect === 'grant') {
                        $expand($assignment, $includeTeamScopedAssignments, $codeScopes);
                    } elseif ($effect === 'deny') {
                        $denials[] = $assignment;
                    }
                }

                if ($denials !== []) {
                    $codeScopes = array_values(array_filter(
                        $codeScopes,
                        fn (array $scope) => ! collect($denials)
                            ->contains(fn (UserRoleAssignment $denial) => $this->scopeCoveredByDenial($scope, $denial))
                    ));

                    // A site-level deny carves individual sites out of the
                    // broader (campaign or organisational) pairs instead of
                    // matching a pair exactly.
                    foreach ($codeScopes as $index => $scope) {
                        if ($scope['site_id'] !== null) {
                            continue;
                        }

                        $except = $scope['except_site_ids'] ?? [];
                        foreach ($denials as $denial) {
                            $deniedSiteId = $this->deniedSiteId($denial);
                            if ($deniedSiteId !== null
                                && ($deniedSite = MissionSite::find($deniedSiteId)) !== null
                                && (int) $deniedSite->campaign_id === (int) $scope['campaign_id']) {
                                $except[] = $deniedSiteId;
                            }
                        }

                        if ($except !== []) {
                            $codeScopes[$index]['except_site_ids'] = array_values(array_unique($except));
                        }
                    }
                }

                $result = array_merge($result, $codeScopes);
            }
            $scopes = $result;
        }

        // De-duplicate identical (campaign, site) pairs, merging their
        // excepted sites.
        $unique = [];
        foreach ($scopes as $scope) {
            $key = $scope['campaign_id'].':'.$scope['site_id'];
            if (isset($unique[$key])) {
                $unique[$key]['except_site_ids'] = array_values(array_unique(array_merge(
                    $unique[$key]['except_site_ids'] ?? [],
                    $scope['except_site_ids'] ?? []
                )));

                continue;
            }
            $unique[$key] = $scope;
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
     * The concrete site id a site- or team-scoped deny override applies to,
     * or null when the denial is not site-specific.
     */
    private function deniedSiteId(UserRoleAssignment $denial): ?int
    {
        $siteId = match ($denial->scope_type) {
            'site' => $denial->scoped_site_id,
            'team' => ($team = Team::find((int) $denial->scoped_team_id))?->mission_site_id,
            default => null,
        };

        return $siteId !== null ? (int) $siteId : null;
    }

    /**
     * Whether a granted scope pair falls inside the area an explicit deny
     * override covers. Used to narrow getAuthorisedScopes() results.
     *
     * @param  array{campaign_id: int|null, site_id: int|null}  $scope
     */
    private function scopeCoveredByDenial(array $scope, UserRoleAssignment $denial): bool
    {
        return match ($denial->scope_type) {
            'global' => true,
            'org_unit' => $denial->scoped_org_unit_id !== null
                && $scope['campaign_id'] !== null
                && ($deniedCampaign = Campaign::find((int) $scope['campaign_id'])) !== null
                && $this->orgUnitCoversOrgUnit((int) $denial->scoped_org_unit_id, (int) $deniedCampaign->lead_org_unit_id),
            'campaign' => (int) $scope['campaign_id'] === (int) $denial->scoped_campaign_id,
            'site' => $scope['site_id'] !== null && (int) $scope['site_id'] === (int) $denial->scoped_site_id,
            'team' => ($deniedTeam = Team::find((int) $denial->scoped_team_id)) !== null
                && $scope['site_id'] !== null
                && (int) $scope['site_id'] === (int) $deniedTeam->mission_site_id
                && (int) $scope['campaign_id'] === (int) $deniedTeam->campaign_id,
            default => false,
        };
    }

    /**
     * Resolve the concrete target scope of an assignment (model) or of a
     * validated role-assignment payload.
     *
     * @return array{0: string, 1: int|null}
     */
    public function assignmentScope(UserRoleAssignment|array $source): array
    {
        if ($source instanceof UserRoleAssignment) {
            $scopeType = (string) $source->scope_type;
            $scopeId = match ($scopeType) {
                'org_unit' => $source->scoped_org_unit_id,
                'campaign' => $source->scoped_campaign_id,
                'site' => $source->scoped_site_id,
                'team' => $source->scoped_team_id,
                default => null,
            };
        } else {
            $scopeType = (string) ($source['scope_type'] ?? '');
            $scopeId = match ($scopeType) {
                'org_unit' => $source['scoped_org_unit_id'] ?? null,
                'campaign' => $source['scoped_campaign_id'] ?? null,
                'site' => $source['scoped_site_id'] ?? null,
                'team' => $source['scoped_team_id'] ?? null,
                default => null,
            };
        }

        return [$scopeType, $scopeId !== null ? (int) $scopeId : null];
    }

    /**
     * Whether a configured, active permission delegation authorises granting
     * $permissionCode at the given target scope.
     *
     * A delegation recorded at the target scope itself OR at any scope that
     * covers it (parent site/campaign, ancestor org unit, global) qualifies.
     */
    public function hasPermissionDelegation(string $permissionCode, string $targetType, ?int $targetId): bool
    {
        $permissionId = Permission::where('code', $permissionCode)->value('id');
        if (! $permissionId) {
            return false;
        }

        foreach ($this->delegationScopesFor($targetType, $targetId) as [$scopeType, $scopeId, $column]) {
            $query = DB::table('permission_delegations')
                ->where('permission_id', $permissionId)
                ->where('scope_type', $scopeType)
                ->where('is_active', true);

            if ($column !== null) {
                $query->where($column, $scopeId);
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scope candidates that may legitimately carry a delegation covering the
     * given target: the target itself first, then every scope covering it.
     *
     * @return array<int, array{0: string, 1: int|null, 2: string|null}>
     */
    private function delegationScopesFor(string $targetType, ?int $targetId): array
    {
        $columns = [
            'org_unit' => 'scoped_org_unit_id',
            'campaign' => 'scoped_campaign_id',
            'site' => 'scoped_site_id',
            'team' => 'scoped_team_id',
        ];

        $candidates = [];
        $orgCandidates = function (int $orgUnitId) use ($columns, &$candidates): void {
            // A delegation scoped to the unit or to any of its ancestors covers it.
            $current = OrgUnit::find($orgUnitId);
            while ($current) {
                $candidates[] = ['org_unit', (int) $current->id, $columns['org_unit']];
                $current = $current->parent_id !== null ? OrgUnit::find((int) $current->parent_id) : null;
            }
        };

        switch ($targetType) {
            case 'global':
                break;

            case 'org_unit':
                if ($targetId === null) {
                    return [];
                }
                $orgCandidates($targetId);
                break;

            case 'campaign':
                $campaign = $targetId !== null ? Campaign::find($targetId) : null;
                if (! $campaign) {
                    return [];
                }
                $candidates[] = ['campaign', (int) $campaign->id, $columns['campaign']];
                if ($campaign->lead_org_unit_id !== null) {
                    $orgCandidates((int) $campaign->lead_org_unit_id);
                }
                break;

            case 'site':
                $site = $targetId !== null ? MissionSite::find($targetId) : null;
                if (! $site) {
                    return [];
                }
                $candidates[] = ['site', (int) $site->id, $columns['site']];
                $campaign = Campaign::find((int) $site->campaign_id);
                if ($campaign) {
                    $candidates[] = ['campaign', (int) $campaign->id, $columns['campaign']];
                    if ($campaign->lead_org_unit_id !== null) {
                        $orgCandidates((int) $campaign->lead_org_unit_id);
                    }
                }
                break;

            case 'team':
                $team = $targetId !== null ? Team::find($targetId) : null;
                if (! $team) {
                    return [];
                }
                $candidates[] = ['team', (int) $team->id, $columns['team']];
                if ($team->mission_site_id !== null) {
                    $candidates[] = ['site', (int) $team->mission_site_id, $columns['site']];
                }
                $campaign = Campaign::find((int) $team->campaign_id);
                if ($campaign) {
                    $candidates[] = ['campaign', (int) $campaign->id, $columns['campaign']];
                    if ($campaign->lead_org_unit_id !== null) {
                        $orgCandidates((int) $campaign->lead_org_unit_id);
                    }
                }
                break;

            default:
                return [];
        }

        $candidates[] = ['global', null, null];

        return $candidates;
    }

    // ── Resource distribution review ──────────────────────────────────────────

    /**
     * Whether the user may approve or reject a resource distribution request.
     *
     * Requires resources.approve_distribute:
     *   - globally, or
     *   - for private-audience requests, at the resource's origin assignment
     *     scope, or
     *   - at the request's audience target scope
     *     (org unit / campaign / site / team).
     */
    public function canApproveDistribute(User $user, ResourceDistributionRequest $request): bool
    {
        if ($this->hasGlobalPermission($user, 'resources.approve_distribute')) {
            return true;
        }

        if ($request->audience_type === 'private') {
            $origin = UserRoleAssignment::find((int) $request->resource?->origin_assignment_id);
            if (! $origin) {
                return false;
            }

            [$scopeType, $scopeId] = $this->assignmentScope($origin);

            return $scopeType !== ''
                && $this->hasPermissionAtAssignmentScope($user, 'resources.approve_distribute', $scopeType, $scopeId);
        }

        return $this->hasPermissionForDistributionTarget(
            $user,
            'resources.approve_distribute',
            $request->audience_type,
            $request->target_org_unit_id,
            $request->target_campaign_id,
            $request->target_site_id,
            $request->target_team_id
        );
    }

    /**
     * Resolve a permission at one distribution request's audience target scope.
     */
    private function hasPermissionForDistributionTarget(
        User $user,
        string $permission,
        string $type,
        ?int $orgUnitId,
        ?int $campaignId,
        ?int $siteId,
        ?int $teamId
    ): bool {
        if ($type === 'campaign' && $campaignId !== null) {
            $campaign = Campaign::find($campaignId);

            return $campaign !== null && $this->hasPermissionForCampaign($user, $permission, $campaign);
        }

        if ($type === 'site' && $siteId !== null) {
            $site = MissionSite::find($siteId);

            return $site !== null && $this->hasPermissionForSite($user, $permission, $site);
        }

        if ($type === 'team' && $teamId !== null) {
            $team = Team::find($teamId);

            return $team !== null && $this->hasPermissionForTeam($user, $permission, $team);
        }

        if (in_array($type, ['union', 'conference', 'mission_field', 'zone', 'church_district', 'local_church'], true)
            && $orgUnitId !== null) {
            return $this->hasPermissionForOrgUnit($user, $permission, $orgUnitId);
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
     * @param  array<int, array{campaign_id: int|null, site_id: int|null}>  $scopes
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
                    if ($scope['site_id'] !== null) {
                        if ($siteColumn !== null) {
                            $inner->where($siteColumn, $scope['site_id']);
                        }
                    } elseif ($siteColumn !== null && ! empty($scope['except_site_ids'])) {
                        // The pair is campaign-broad but carries a carve-out of
                        // sites explicitly denied for this permission.
                        $inner->whereNotIn($siteColumn, $scope['except_site_ids']);
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
                'global' => true,
                'org_unit' => $this->orgUnitCoversOrgUnit(
                    $a->scoped_org_unit_id,
                    $campaign->lead_org_unit_id
                ),
                'campaign' => (int) $a->scoped_campaign_id === (int) $campaign->id
                              && ($site === null || (int) $campaign->id === (int) $site->campaign_id),
                'site' => $site !== null
                              && (int) $a->scoped_site_id === (int) $site->id,
                // A team-scoped role covers exactly the team it was granted for.
                // It also covers the SITE that team belongs to (so a team member
                // can load their own site context), but it must NOT match a
                // sibling team in the same site. The conditions below are
                // mutually exclusive: the site arm only applies when no specific
                // team was requested, which prevents sibling-team leakage.
                // $includeTeamScopedAssignments = false removes the site arm
                // entirely so that a team never inherits the site's authority.
                'team' => $includeTeamScopedAssignments && $a->scoped_team_id !== null && (
                    ($team !== null && (int) $a->scoped_team_id === (int) $team->id)
                    || ($team === null && $site !== null && Team::where('id', $a->scoped_team_id)->where('mission_site_id', $site->id)->exists())
                ),
                default => false,
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

            $this->assignmentCache[$user->id] = UserRoleAssignment::with(['role', 'permissionVersion.permissions', 'permissionOverrides.permission'])
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
                ->whereHas('role', fn ($q) => $q->where('is_active', true))
                ->get();

            // Assignments created without an explicit permission version fall
            // back to the role's latest published version so their effective
            // permissions still resolve instead of silently denying everything.
            foreach ($this->assignmentCache[$user->id] as $assignment) {
                if (! $assignment->permissionVersion) {
                    $fallback = RolePermissionVersion::where('system_role_id', $assignment->system_role_id)
                        ->whereNotNull('published_at')
                        ->orderByDesc('version')
                        ->with('permissions')
                        ->first();
                    $assignment->setRelation('permissionVersion', $fallback);
                }
            }
        }

        return $this->assignmentCache[$user->id];
    }

    /**
     * Clear the cached permission assignments for a user.
     * Called by model observers when assignments change.
     */
    public function clearUserCache(?User $user): void
    {
        if ($user) {
            unset($this->assignmentCache[$user->id]);
        }
    }

    /**
     * The effective effect of one assignment for a permission.
     *
     * An explicit override wins; otherwise the assignment's pinned, immutable
     * permission version decides. Null means the permission is simply absent
     * from that assignment (neither granted nor denied).
     *
     * @return 'grant'|'deny'|null
     */
    private function permissionEffect(UserRoleAssignment $assignment, string $permission): ?string
    {
        $override = $assignment->permissionOverrides->first(fn ($item) => $item->permission?->code === $permission);
        if ($override) {
            return $override->effect;
        }

        if (! $assignment->permissionVersion
            || ! $assignment->permissionVersion->permissions->contains(fn ($item) => $item->code === $permission)) {
            return null;
        }

        return 'grant';
    }

    /**
     * Decide a permission across a set of applicable assignments.
     *
     * A denial in ANY applicable assignment wins over grants elsewhere in the
     * same set; otherwise a grant in any of the set's assignments decides
     * true.
     */
    private function effectivePermissionDecision(Collection $assignments, string|array $permissions): bool
    {
        foreach ((array) $permissions as $permission) {
            $effects = $assignments->map(fn (UserRoleAssignment $assignment) => $this->permissionEffect($assignment, $permission));

            if ($effects->contains('deny')) {
                continue;
            }

            if ($effects->contains('grant')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether ONE single assignment effectively grants any of the permissions
     * (overrides honoured). For decisions across several assignments use
     * effectivePermissionDecision() so denials are honoured.
     *
     * @param  string|array<int, string>  $permissions
     */
    private function assignmentGrantsPermission(UserRoleAssignment $assignment, string|array $permissions): bool
    {
        foreach ((array) $permissions as $permission) {
            if ($this->permissionEffect($assignment, $permission) === 'grant') {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the permission codes a single assignment effectively carries:
     * its pinned permission version's codes, minus override denials, plus
     * override grants.
     *
     * Returns an empty list when the assignment resolves to no permission
     * version.
     *
     * @return array<int, string>
     */
    private function assignmentPermissionCodes(UserRoleAssignment $assignment): array
    {
        if (! $assignment->relationLoaded('permissionVersion')
            || ! $assignment->permissionVersion
            || ! $assignment->permissionVersion->relationLoaded('permissions')) {
            return [];
        }

        $codes = $assignment->permissionVersion->permissions->pluck('code')->values()->all();

        if ($assignment->relationLoaded('permissionOverrides') && $assignment->permissionOverrides->isNotEmpty()) {
            $denied = [];
            foreach ($assignment->permissionOverrides as $override) {
                if ($override->effect === 'deny' && $override->permission) {
                    $denied[] = $override->permission->code;
                }
            }
            $codes = array_values(array_filter($codes, fn (string $code) => ! in_array($code, $denied, true)));

            foreach ($assignment->permissionOverrides as $override) {
                if ($override->effect === 'grant' && $override->permission) {
                    $codes[] = $override->permission->code;
                }
            }
            $codes = array_values(array_unique($codes));
        }

        return $codes;
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
        $ids = [$rootId];
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

            $ids = array_merge($ids, $children);
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
