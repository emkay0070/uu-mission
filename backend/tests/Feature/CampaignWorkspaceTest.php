<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignAssignment;
use App\Models\MissionSite;
use App\Models\MissionaryProfile;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AuthorizationService;
use App\Services\WorkspaceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\PublishesRolePermissions;

/**
 * CampaignWorkspaceTest — authority boundaries of the Campaign Management workspace.
 *
 * Verifies that "Campaign Management" is derived from ACTUAL authority, never
 * from org membership, a site's campaign membership, or a deployment:
 *
 *   1. A campaign leader receives exactly the campaigns they are appointed to.
 *   2. A campaign leader cannot reach a campaign outside their appointment.
 *   3. An organisational officer receives campaign access only where their
 *      organisational scope (and an explicit campaign permission) qualifies —
 *      including campaigns led by descendant units, but never sibling ones.
 *   4. An organisational officer never receives Platform Administration.
 *   5. An organisational role with no campaign permission does not qualify.
 *   6. A site-scoped user never gains campaign-wide authority.
 *   7. A deployment alone never grants Campaign Management.
 *   8. Expired and revoked appointments produce no workspace.
 *   9. Multiple appointed campaigns are all resolved.
 *  10. The Missionary and Site Operations workspaces remain intact.
 *
 * Workspace descriptors are read from GET /api/v1/auth/me, the authoritative
 * source. Laravel caches the resolved controller — and with it the scoped
 * AuthorizationService it was constructed with — on the Route for the lifetime
 * of the test's application instance, so a test that mutates an assignment and
 * then re-reads it over HTTP would be served a pre-mutation engine. Such a test
 * re-resolves through a freshly built engine instead (see the revocation test).
 */
class CampaignWorkspaceTest extends TestCase
{
    use PublishesRolePermissions;
    use RefreshDatabase;

    private OrgUnit $uu;

    private OrgUnit $cuc;

    private OrgUnit $ncc;

    private OrgUnit $zone;

    private Campaign $campaignA;

    private Campaign $campaignB;

    private Campaign $campaignD;

    private MissionSite $siteA1;

    private SystemRole $roleCampaignLeader;

    private SystemRole $roleDirector;

    private SystemRole $roleSiteCoordinator;

    private SystemRole $roleMissionary;

    private SystemRole $roleOrgOfficerWithoutCampaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. CAMPAIGN LEADER — only the appointed campaign, and no site authority
    // ══════════════════════════════════════════════════════════════════════════

    public function test_campaign_management_home_route_uses_the_campaign_shell(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $workspace = $this->campaignWorkspace($leader);

        $this->assertNotNull($workspace);
        $this->assertSame('/campaign-management', $workspace['home']);
    }

    public function test_campaign_leader_receives_only_authorised_campaign_contexts(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $workspace = $this->campaignWorkspace($leader);
        $this->assertNotNull($workspace);

        // Only Campaign Alpha, never its sibling.
        $this->assertSame([$this->campaignA->id], $this->campaignContextIds($workspace));

        // Campaign oversight is not site authority: no context carries a site
        // or team, and the leader holds no Site Operations workspace.
        foreach ($workspace['contexts'] as $context) {
            $this->assertNull($context['site_id']);
            $this->assertNull($context['team_id']);
        }
        $this->assertNotContains('site_operations', $this->workspaceKeys($leader));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. CAMPAIGN LEADER — cannot reach a campaign outside the appointment
    // ══════════════════════════════════════════════════════════════════════════

    public function test_campaign_leader_cannot_access_another_campaign(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        // The other campaign is refused outright...
        $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignB->id}")->assertStatus(403);
        $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignB->id}/stats")->assertStatus(403);

        // ...while the appointed campaign stays fully reachable (the permission
        // itself still works — we are not merely returning 403 for everything).
        $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}")->assertOk();
        $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/stats")->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. ORGANISATIONAL OFFICER — access bounded by organisational jurisdiction
    // ══════════════════════════════════════════════════════════════════════════

    public function test_org_officer_receives_campaign_access_only_where_org_scope_qualifies(): void
    {
        // Appointed at the Conference. The Conference leads Campaign Alpha, and
        // its descendant Zone leads Campaign Delta. Campaign Beta is led by a
        // sibling conference, outside the jurisdiction.
        $director = $this->createUserWithOrgUnitRole($this->roleDirector, $this->cuc);

        $workspace = $this->campaignWorkspace($director);
        $this->assertNotNull($workspace);

        $ids = $this->campaignContextIds($workspace);
        $this->assertContains($this->campaignA->id, $ids);   // led by the unit itself
        $this->assertContains($this->campaignD->id, $ids);   // led by a descendant unit
        $this->assertNotContains($this->campaignB->id, $ids); // sibling jurisdiction

        // The sibling campaign is also refused at the resource layer.
        $this->actingAs($director, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignB->id}")->assertStatus(403);
        $this->actingAs($director, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}")->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. ORGANISATIONAL OFFICER — never Platform Administration
    // ══════════════════════════════════════════════════════════════════════════

    public function test_org_officer_does_not_receive_platform_administration(): void
    {
        $director = $this->createUserWithOrgUnitRole($this->roleDirector, $this->cuc);

        $keys = $this->workspaceKeys($director);

        $this->assertContains('campaign_management', $keys);
        $this->assertNotContains('platform_administration', $keys);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5. AN ORGANISATIONAL ROLE DOES NOT AUTOMATICALLY QUALIFY
    // ══════════════════════════════════════════════════════════════════════════

    public function test_organisational_role_without_campaign_permission_does_not_qualify(): void
    {
        $officer = $this->createUserWithOrgUnitRole($this->roleOrgOfficerWithoutCampaign, $this->cuc);

        $this->assertNull($this->campaignWorkspace($officer));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 6. SITE-SCOPED USER — no campaign-wide authority
    // ══════════════════════════════════════════════════════════════════════════

    public function test_site_scoped_user_does_not_gain_campaign_wide_access(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $keys = $this->workspaceKeys($coordinator);

        // The coordinator keeps their legitimate site workspace...
        $this->assertContains('site_operations', $keys);
        // ...but the site belonging to a campaign grants nothing campaign-wide.
        $this->assertNotContains('campaign_management', $keys);

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignB->id}")->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 7. A DEPLOYMENT ALONE NEVER GRANTS CAMPAIGN MANAGEMENT
    // ══════════════════════════════════════════════════════════════════════════

    public function test_deployment_alone_does_not_grant_campaign_management(): void
    {
        $user = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $user->id]);

        CampaignAssignment::create([
            'campaign_id' => $this->campaignA->id,
            'person_id' => $person->id,
            'mission_site_id' => $this->siteA1->id,
            'status' => 'confirmed',
            'assigned_by_user_id' => $user->id,
        ]);

        // No role assignment and no missionary profile: the deployment is a
        // fact, not authority, so no workspace is produced at all.
        $this->assertSame([], $this->workspaces($user));
        $this->assertNull($this->campaignWorkspace($user));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 8. EXPIRED / REVOKED APPOINTMENTS PRODUCE NO WORKSPACE
    // ══════════════════════════════════════════════════════════════════════════

    public function test_expired_campaign_assignment_does_not_produce_workspace(): void
    {
        $user = User::factory()->create();
        Person::factory()->create(['user_id' => $user->id]);

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $this->roleCampaignLeader->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $this->campaignA->id,
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->assertNull($this->campaignWorkspace($user));
    }

    public function test_revoked_campaign_assignment_does_not_produce_workspace(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        // While the appointment stands, the workspace is available.
        $this->assertNotNull($this->campaignWorkspace($leader));

        // Revoke it through the model, exactly as the revocation flow does:
        // the assignment stops being 'active', so the authorization engine
        // must stop resolving it.
        $leader->roleAssignments()->firstOrFail()->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => 'Appointment withdrawn',
        ]);

        // Re-resolve through the real resolver on a CLEAN authorization engine,
        // which mirrors the fresh container every production HTTP request gets.
        //
        // This cannot be asserted over HTTP here: Laravel caches the resolved
        // controller (and therefore its constructor-injected, scoped services)
        // on the Route for the lifetime of the test's application instance, so
        // a second request in the same test would reuse the engine that already
        // cached the pre-revocation assignment. Rebuilding the engine proves the
        // revocation itself removes access rather than relying on container
        // side-effects.
        $workspaces = (new WorkspaceResolver(new AuthorizationService))->resolve($leader);

        $this->assertNull(collect($workspaces)->firstWhere('key', 'campaign_management'));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 9. MULTIPLE APPOINTED CAMPAIGNS ARE ALL RESOLVED
    // ══════════════════════════════════════════════════════════════════════════

    public function test_multiple_campaign_contexts_are_resolved(): void
    {
        $user = User::factory()->create();
        Person::factory()->create(['user_id' => $user->id]);

        foreach ([$this->campaignA, $this->campaignB] as $campaign) {
            UserRoleAssignment::create([
                'user_id' => $user->id,
                'system_role_id' => $this->roleCampaignLeader->id,
                'scope_type' => 'campaign',
                'scoped_campaign_id' => $campaign->id,
            ]);
        }

        $workspace = $this->campaignWorkspace($user);
        $this->assertNotNull($workspace);

        $expected = collect([$this->campaignA->id, $this->campaignB->id])->sort()->values()->all();
        $this->assertSame($expected, $this->campaignContextIds($workspace));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 10. EXISTING WORKSPACES REMAIN INTACT
    // ══════════════════════════════════════════════════════════════════════════

    public function test_missionary_workspace_remains_intact(): void
    {
        $user = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $user->id]);
        MissionaryProfile::create(['person_id' => $person->id]);

        $keys = $this->workspaceKeys($user);

        $this->assertContains('missionary', $keys);
        $this->assertNotContains('campaign_management', $keys);
    }

    public function test_site_operations_workspace_remains_intact(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $siteOps = collect($this->workspaces($coordinator))->firstWhere('key', 'site_operations');

        $this->assertNotNull($siteOps);
        $this->assertSame('/site-operations', $siteOps['home']);
        $siteIds = collect($siteOps['contexts'])->pluck('site_id')->all();
        $this->assertContains($this->siteA1->id, $siteIds);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    private function workspaces(User $user): array
    {
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        return $response->json('user.workspaces') ?? [];
    }

    /** @return array<int, string> */
    private function workspaceKeys(User $user): array
    {
        return collect($this->workspaces($user))->pluck('key')->all();
    }

    /** @return array<string, mixed>|null */
    private function campaignWorkspace(User $user): ?array
    {
        return collect($this->workspaces($user))->firstWhere('key', 'campaign_management');
    }

    /**
     * The campaign ids in a workspace descriptor, sorted for stable comparison.
     *
     * @param  array<string, mixed>  $workspace
     * @return array<int, int|null>
     */
    private function campaignContextIds(array $workspace): array
    {
        return collect($workspace['contexts'] ?? [])
            ->pluck('campaign_id')
            ->sort()
            ->values()
            ->all();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FIXTURE BUILDERS
    // ══════════════════════════════════════════════════════════════════════════

    private function buildFixtures(): void
    {
        $typeUnion = OrgUnitType::factory()->create(['code' => 'union', 'label' => 'Union']);
        $typeConf = OrgUnitType::factory()->create(['code' => 'conference', 'label' => 'Conference']);
        $typeZone = OrgUnitType::factory()->create(['code' => 'zone', 'label' => 'Zone']);

        $this->uu = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeUnion->id,
            'name' => 'Uganda Union',
        ]);
        $this->cuc = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeConf->id,
            'parent_id' => $this->uu->id,
            'name' => 'Central Uganda Conference',
        ]);
        $this->ncc = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeConf->id,
            'parent_id' => $this->uu->id,
            'name' => 'Northern Uganda Conference',
        ]);
        $this->zone = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeZone->id,
            'parent_id' => $this->cuc->id,
            'name' => 'Kampala Zone',
        ]);

        $pt = ProgrammeType::factory()->create(['code' => 'global_mission', 'label' => 'Global Mission']);
        $programme = Programme::factory()->create([
            'programme_type_id' => $pt->id,
            'owning_org_unit_id' => $this->uu->id,
        ]);

        $this->campaignA = Campaign::factory()->create([
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $this->cuc->id,
            'name' => 'Campaign Alpha',
            'status' => 'active',
        ]);
        $this->campaignB = Campaign::factory()->create([
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $this->ncc->id,
            'name' => 'Campaign Beta',
            'status' => 'active',
        ]);
        $this->campaignD = Campaign::factory()->create([
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $this->zone->id,
            'name' => 'Campaign Delta',
            'status' => 'active',
        ]);

        $this->siteA1 = MissionSite::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'name' => 'Site A1',
            'status' => 'active',
        ]);

        // A campaign-scoped leader: oversight only, never site authority.
        $this->roleCampaignLeader = $this->createRole('campaign_leader', 'campaign', [
            'campaigns.view', 'campaigns.edit', 'campaigns.archive',
            'sites.view',
            'assignments.view', 'assignments.manage',
            'report.view', 'report.review',
            'outcomes.view',
        ]);

        // An organisational appointment that DOES carry campaign oversight.
        $this->roleDirector = $this->createRole('union_director', 'org_unit', [
            'org.view',
            'campaigns.view', 'campaigns.edit',
            'sites.view',
            'assignments.view',
            'report.view', 'report.review',
            'people.view',
        ]);

        // An organisational appointment that does NOT carry campaign oversight.
        $this->roleOrgOfficerWithoutCampaign = $this->createRole('org_officer', 'org_unit', [
            'org.view',
            'people.view',
        ]);

        // A site coordinator: the full site-operations surface, but no
        // campaign-oversight permission (note: sites.view exists; sites.create
        // — which would qualify for Campaign Management — deliberately does not).
        $this->roleSiteCoordinator = $this->createRole('site_coordinator', 'site', [
            'sites.view', 'sites.edit',
            'assignments.view', 'assignments.manage',
            'attendance.submit', 'attendance.verify',
            'report.submit', 'report.view',
            'outcomes.view',
            'people.view',
        ]);

        // A field missionary: personal fieldwork only.
        $this->roleMissionary = $this->createRole('missionary', 'site', [
            'attendance.submit',
            'outcomes.record',
            'outreach-contacts.view',
        ]);
    }

    private function createRole(string $code, string $scopeType, array $permCodes): SystemRole
    {
        $role = SystemRole::factory()->create([
            'code' => $code,
            'label' => ucwords(str_replace('_', ' ', $code)),
            'scope_type' => $scopeType,
        ]);

        $permIds = collect($permCodes)->map(function (string $pc) {
            return Permission::firstOrCreate(
                ['code' => $pc],
                ['label' => $pc, 'module' => explode('.', $pc)[0]]
            )->id;
        })->toArray();

        $this->publishRolePermissions($role, $permIds);

        return $role;
    }

    private function createUserWithCampaignRole(SystemRole $role, Campaign $campaign): User
    {
        $user = User::factory()->create();
        Person::factory()->create(['user_id' => $user->id]);

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaign->id,
        ]);

        return $user;
    }

    private function createUserWithOrgUnitRole(SystemRole $role, OrgUnit $orgUnit): User
    {
        $user = User::factory()->create();
        Person::factory()->create(['user_id' => $user->id]);

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'org_unit',
            'scoped_org_unit_id' => $orgUnit->id,
        ]);

        return $user;
    }

    private function createUserWithSiteRole(SystemRole $role, MissionSite $site): User
    {
        $user = User::factory()->create();
        Person::factory()->create(['user_id' => $user->id]);

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'site',
            'scoped_site_id' => $site->id,
        ]);

        return $user;
    }
}
