<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * WorkspaceResolver — derives the operational workspaces a person may enter.
 *
 * WHAT THIS IS
 * ------------
 * A workspace is an operational EXPERIENCE, not a role label. This resolver
 * answers exactly one question: "which workspaces is this person authorised
 * and responsible for, and in which contexts?" It never grants access to a
 * record — every operation is still authorised by the policies against the
 * real resource scope.
 *
 * WHERE THE ANSWER COMES FROM
 * ---------------------------
 * Only the authorization engine (`AuthorizationService`), which already
 * applies assignment status, the role's `is_active` flag, `valid_from` /
 * `valid_until`, revocation, the assignment's PINNED role-permission version
 * and any per-assignment overrides. Deployment rows (CampaignAssignment) are
 * deliberately NOT consulted: being deployed to a site does not independently
 * grant operational authority.
 *
 * THE FOUR WORKSPACES
 * -------------------
 *   platform_administration  global-scoped authority that actually carries a
 *                            platform-administration permission.
 *   campaign_management      campaign- or org_unit-scoped authority that
 *                            carries campaign oversight permissions.
 *   site_operations          site-scoped authority that carries site
 *                            operations permissions.
 *   missionary               the person has a missionary profile.
 *
 * DELIBERATELY NOT GRANTED HERE
 * -----------------------------
 *   - An org_unit-scoped role does NOT imply Platform Administration: that
 *     workspace requires an explicitly global-scoped assignment that carries
 *     an administration permission (principle B).
 *   - A team-scoped assignment never becomes site-wide authority: the site
 *     workspace is only ever built from `site`-scoped assignments (principle C).
 */
class WorkspaceResolver
{
    /** Personal missionary workspace. */
    public const MISSIONARY = 'missionary';

    /** Operational management of an assigned mission site. */
    public const SITE_OPERATIONS = 'site_operations';

    /** Oversight and coordination of campaigns and their sites. */
    public const CAMPAIGN_MANAGEMENT = 'campaign_management';

    /** Platform-wide accounts, RBAC, organisation hierarchy and configuration. */
    public const PLATFORM_ADMINISTRATION = 'platform_administration';

    /**
     * Permission codes that only genuine platform-administration authority
     * carries. Holding any of these at GLOBAL scope is what distinguishes a
     * platform administrator from an organisational officer.
     */
    private const PLATFORM_PERMISSIONS = [
        'admin.users.manage',
        'admin.roles.manage',
        'admin.org.manage',
    ];

    /**
     * Permission codes that express oversight of campaigns and their sites.
     * Held at campaign or org_unit scope, they qualify for Campaign Management.
     */
    private const CAMPAIGN_PERMISSIONS = [
        'campaigns.create',
        'campaigns.edit',
        'campaigns.view',
        'campaigns.archive',
        'sites.create',
    ];

    /**
     * Permission codes that express responsibility for running a SITE's
     * operations (people, teams, activities, attendance, reporting, follow-up).
     *
     * A plain field missionary does not hold any of these, so a missionary
     * whose role happens to be site-scoped is not mistaken for a site officer.
     */
    private const SITE_OPERATIONS_PERMISSIONS = [
        'assignments.manage',
        'assignments.view',
        'sites.edit',
        'activities.create',
        'activities.update',
        'activities.delete',
        'attendance.verify',
        'report.review',
        'outcomes.manage_followup',
        'people.create',
        'people.edit',
    ];

    public function __construct(private AuthorizationService $auth) {}

    /**
     * Resolve every workspace the user may enter, in descending order of
     * operational precedence (platform → campaign → site → missionary).
     *
     * An empty array means the user holds no operational workspace yet; the
     * client must show a neutral state rather than forcing them into one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolve(User $user): array
    {
        $workspaces = [];

        foreach ([
            $this->platformAdministration($user),
            $this->campaignManagement($user),
            $this->siteOperations($user),
            $this->missionary($user),
        ] as $workspace) {
            if ($workspace !== null) {
                $workspaces[] = $workspace;
            }
        }

        return $workspaces;
    }

    /**
     * Platform Administration — requires GLOBAL scope plus a genuine
     * administration permission. An org_unit officer never qualifies.
     */
    private function platformAdministration(User $user): ?array
    {
        $global = $this->granting($user, self::PLATFORM_PERMISSIONS)
            ->where('scope_type', 'global');

        if ($global->isEmpty()) {
            return null;
        }

        $roles = $this->roleCodes($global);

        return [
            'key' => self::PLATFORM_ADMINISTRATION,
            'label' => 'Platform Administration',
            'description' => 'Platform-wide accounts, roles, organisation hierarchy and configuration.',
            'home' => '/admin/campaigns',
            'role_codes' => $roles,
            'contexts' => [[
                'scope_type' => 'global',
                'scope_id' => null,
                'label' => 'Platform-wide',
                'campaign_id' => null,
                'campaign_name' => null,
                'site_id' => null,
                'site_name' => null,
                'team_id' => null,
                'team_name' => null,
                'roles' => $roles,
            ]],
        ];
    }

    /**
     * Campaign Management — campaign- or org_unit-scoped authority that
     * actually carries campaign oversight permissions.
     *
     * Contexts are expanded to CONCRETE campaigns so the interface can offer a
     * selector listing exactly the campaigns the person may oversee:
     *   - a campaign-scoped assignment yields its own campaign;
     *   - an org_unit-scoped appointment is expanded into the campaigns led by
     *     that unit or any of its descendants (never wider).
     *
     * The expansion is derived from the authorization engine's own hierarchy
     * resolution, so it can never cover a campaign outside the appointment.
     */
    private function campaignManagement(User $user): ?array
    {
        $assignments = $this->granting($user, self::CAMPAIGN_PERMISSIONS)
            ->whereIn('scope_type', ['campaign', 'org_unit']);

        if ($assignments->isEmpty()) {
            return null;
        }

        return [
            'key' => self::CAMPAIGN_MANAGEMENT,
            'label' => 'Campaign Management',
            'description' => 'Oversight and coordination of campaigns and their mission sites.',
            'home' => '/campaign-management',
            'role_codes' => $this->roleCodes($assignments),
            'contexts' => $this->buildCampaignContexts($assignments),
        ];
    }

    /**
     * Site Operations — SITE-scoped authority that carries site operations
     * permissions. A team-scoped assignment is never widened into a site.
     */
    private function siteOperations(User $user): ?array
    {
        $assignments = $this->granting($user, self::SITE_OPERATIONS_PERMISSIONS)
            ->where('scope_type', 'site');

        if ($assignments->isEmpty()) {
            return null;
        }

        return [
            'key' => self::SITE_OPERATIONS,
            'label' => 'Site Operations',
            'description' => 'Operational management of an assigned mission site.',
            'home' => '/site-operations',
            'role_codes' => $this->roleCodes($assignments),
            'contexts' => $this->buildContexts($assignments),
        ];
    }

    /**
     * Missionary Workspace — available to anyone with a missionary profile.
     * Its contexts are the user's own active assignment scopes, so the
     * workspace can still show which campaign/site/team they are attached to.
     */
    private function missionary(User $user): ?array
    {
        $person = $user->person;

        if (! $person || ! $person->missionaryProfile) {
            return null;
        }

        $assignments = $this->auth->activeAssignmentsFor($user);

        return [
            'key' => self::MISSIONARY,
            'label' => 'Missionary Workspace',
            'description' => 'Your personal mission activities, assignments, resources, learning and communications.',
            'home' => '/dashboard',
            'role_codes' => $this->roleCodes($assignments),
            'contexts' => $this->buildContexts($assignments),
        ];
    }

    /**
     * The active assignments that actually grant at least one of the given
     * permission codes (overrides honoured, pinned versions respected).
     *
     * @param  array<int, string>  $codes
     * @return Collection<int, UserRoleAssignment>
     */
    private function granting(User $user, array $codes): Collection
    {
        return collect($codes)
            ->flatMap(fn (string $code) => $this->auth->getActiveAssignmentsGranting($user, $code))
            ->unique(fn (UserRoleAssignment $assignment) => $assignment->id)
            ->values();
    }

    /**
     * @param  Collection<int, UserRoleAssignment>  $assignments
     * @return array<int, string>
     */
    private function roleCodes(Collection $assignments): array
    {
        return $assignments
            ->map(fn (UserRoleAssignment $assignment) => $assignment->role?->code)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Shape each assignment into a context descriptor the client can act on.
     *
     * A context always carries the resolved campaign/site/team identity so the
     * interface can show — and switch between — every authorised context rather
     * than silently keeping only the first one.
     *
     * @param  Collection<int, UserRoleAssignment>  $assignments
     * @return array<int, array<string, mixed>>
     */
    private function buildContexts(Collection $assignments): array
    {
        if ($assignments->isEmpty()) {
            return [];
        }

        // Load the relations needed to name every context in one pass, then
        // reuse them below instead of triggering a query per assignment.
        $models = new EloquentCollection($assignments->all());
        $models->load(['scopedSite.campaign', 'scopedCampaign', 'scopedTeam.site.campaign', 'scopedOrgUnit']);

        return $models
            ->map(function (UserRoleAssignment $assignment): array {
                $team = $assignment->scopedTeam;
                $site = $assignment->scopedSite;
                $campaign = $assignment->scopedCampaign;

                // A team belongs to a site; that site belongs to a campaign.
                // Resolving them here is CONTEXT ONLY — it never widens the
                // team's authority to the site.
                if ($team) {
                    $site ??= $team->site;
                }
                if ($site && ! $campaign) {
                    $campaign = $site->campaign;
                }

                $scopeId = match ($assignment->scope_type) {
                    'org_unit' => $assignment->scoped_org_unit_id,
                    'campaign' => $assignment->scoped_campaign_id,
                    'site' => $assignment->scoped_site_id,
                    'team' => $assignment->scoped_team_id,
                    default => null,
                };

                $label = match (true) {
                    $assignment->scope_type === 'global' => 'Platform-wide',
                    $assignment->scope_type === 'org_unit' => $assignment->scopedOrgUnit?->name ?? 'Organisational unit',
                    $team !== null => $team->name,
                    $site !== null => $site->name,
                    $campaign !== null => $campaign->name,
                    default => ucfirst(str_replace('_', ' ', (string) $assignment->scope_type)),
                };

                return [
                    'scope_type' => $assignment->scope_type,
                    'scope_id' => $scopeId !== null ? (int) $scopeId : null,
                    'label' => $label,
                    'campaign_id' => $campaign?->id !== null ? (int) $campaign->id : null,
                    'campaign_name' => $campaign?->name,
                    'site_id' => $site?->id !== null ? (int) $site->id : null,
                    'site_name' => $site?->name,
                    'team_id' => $team?->id !== null ? (int) $team->id : null,
                    'team_name' => $team?->name,
                    'roles' => $assignment->role?->code ? [$assignment->role->code] : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Shape Campaign Management assignments into ONE context per campaign.
     *
     * Unlike buildContexts(), which describes the assignment's own scope, this
     * resolves each assignment to the concrete campaigns it authorises so the
     * client can list and switch between them without ever guessing the org
     * hierarchy:
     *
     *   campaign  → the single campaign the assignment names.
     *   org_unit  → every campaign led by the unit or a descendant, via the
     *               authorization engine's own hierarchy resolution.
     *
     * Campaigns are de-duplicated, so a person who holds both a campaign
     * assignment and a covering org_unit appointment still sees the campaign
     * once, with the union of the roles that grant it.
     *
     * @param  Collection<int, UserRoleAssignment>  $assignments
     * @return array<int, array<string, mixed>>
     */
    private function buildCampaignContexts(Collection $assignments): array
    {
        /** @var array<int, array{scope_type: string, scope_id: int|null, roles: array<int, string>}> $byCampaign */
        $byCampaign = [];

        foreach ($assignments as $assignment) {
            $campaignIds = match ($assignment->scope_type) {
                'campaign' => $assignment->scoped_campaign_id !== null
                    ? [(int) $assignment->scoped_campaign_id]
                    : [],
                'org_unit' => $assignment->scoped_org_unit_id !== null
                    ? $this->auth->campaignIdsForOrgUnit((int) $assignment->scoped_org_unit_id)
                    : [],
                default => [],
            };

            foreach ($campaignIds as $campaignId) {
                $byCampaign[$campaignId] ??= [
                    'scope_type' => $assignment->scope_type,
                    'scope_id' => $assignment->scope_type === 'org_unit'
                        ? (int) $assignment->scoped_org_unit_id
                        : $campaignId,
                    'roles' => [],
                ];

                $roleCode = $assignment->role?->code;
                if ($roleCode && ! in_array($roleCode, $byCampaign[$campaignId]['roles'], true)) {
                    $byCampaign[$campaignId]['roles'][] = $roleCode;
                }
            }
        }

        if ($byCampaign === []) {
            return [];
        }

        $campaigns = Campaign::whereIn('id', array_keys($byCampaign))->get()->keyBy('id');

        $contexts = [];
        foreach ($byCampaign as $campaignId => $meta) {
            $campaign = $campaigns->get($campaignId);
            if (! $campaign) {
                continue;
            }

            $contexts[] = [
                'scope_type' => $meta['scope_type'],
                'scope_id' => $meta['scope_id'],
                'label' => $campaign->name,
                'campaign_id' => (int) $campaign->id,
                'campaign_name' => $campaign->name,
                'site_id' => null,
                'site_name' => null,
                'team_id' => null,
                'team_name' => null,
                'roles' => $meta['roles'],
            ];
        }

        return $contexts;
    }
}
