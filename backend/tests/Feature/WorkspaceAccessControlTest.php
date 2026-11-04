<?php

namespace Tests\Feature;

use App\Models\AttendanceRegister;
use App\Models\Campaign;
use App\Models\CampaignAssignment;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\TeamType;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WorkspaceAccessControlTest — permission-driven missionary workspace.
 *
 * Verifies the eight acceptance scenarios for the permission-driven workspace:
 *
 *   1. A user with no operational assignment has no restricted access.
 *   2. A site-scoped user cannot access another site.
 *   3. An organisational appointment grants access without a campaign deployment.
 *   4. Expired / revoked assignments stop access.
 *   5. Assignment history is preserved when access ends.
 *   6. Duplicate checking respects authentication and scope.
 *   7. Missing optional query parameters do not produce a validation error.
 *   8. Legitimate campaign-deployment access continues to work.
 *
 * The AuthorizationService singleton caches a user's active assignments for
 * the lifetime of the container. Tests that mutate an assignment and then
 * re-request must call $this->app->forgetScopedInstances() so the second
 * request re-resolves authorization from the database.
 */
class WorkspaceAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $uu;
    private OrgUnit $cuc;
    private Campaign $campaignA;
    private Campaign $campaignB;
    private MissionSite $siteA1;
    private MissionSite $siteA2;
    private MissionSite $siteB1;
    private Team $teamA1;
    private SystemRole $roleMissionary;
    private SystemRole $roleSiteCoordinator;
    private SystemRole $roleDirector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. NO OPERATIONAL ASSIGNMENT → NO RESTRICTED ACCESS
    // ══════════════════════════════════════════════════════════════════════════

    public function test_user_without_operational_assignment_is_denied_operational_modules(): void
    {
        $user = $this->createUserWithoutOperationalAccess();

        // Having a Person/User account grants nothing. Every operational list
        // fails closed with 403 — not an empty list.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/people')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/outreach-contacts')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance-registers')->assertStatus(403);
    }

    public function test_effective_permissions_are_empty_without_active_assignments(): void
    {
        $user = $this->createUserWithoutOperationalAccess();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.permissions', [])
            ->assertJsonPath('user.scopes', []);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. SITE SCOPE — cannot reach a sibling site
    // ══════════════════════════════════════════════════════════════════════════

    public function test_site_scoped_user_cannot_access_another_site(): void
    {
        $user          = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $ownRegister   = $this->createRegister($this->campaignA, $this->siteA1);
        $otherRegister = $this->createRegister($this->campaignA, $this->siteA2);

        // The list is constrained to the user's site only.
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/attendance-registers')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($ownRegister->id, $ids);
        $this->assertNotContains($otherRegister->id, $ids);

        // Direct access to the sibling site's register is refused outright.
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$otherRegister->id}")
            ->assertStatus(403);

        // ...while the user's own register remains reachable (proves the
        // permission itself still works — we are not just returning 403).
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$ownRegister->id}")
            ->assertOk();
    }

    public function test_site_scoped_user_does_not_see_people_outside_their_site(): void
    {
        $user = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $visible = Person::factory()->create([
            'first_name' => 'Visible',
            'last_name'  => 'Person',
            'registered_by_user_id' => $user->id,
        ]);
        $hidden = Person::factory()->create([
            'first_name' => 'Hidden',
            'last_name'  => 'Person',
            'registered_by_user_id' => null,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/people')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. ORGANISATIONAL APPOINTMENT WITHOUT A CAMPAIGN DEPLOYMENT
    // ══════════════════════════════════════════════════════════════════════════

    public function test_org_appointment_grants_access_without_campaign_deployment(): void
    {
        $director = $this->createUserWithOrgUnitRole($this->roleDirector, $this->cuc);

        // There is deliberately no CampaignAssignment anywhere.
        $this->assertSame(0, CampaignAssignment::count());

        // The org appointment alone authorises campaign and people access.
        $this->actingAs($director, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}")
            ->assertOk();
        $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/people')
            ->assertOk();

        // Workspace context is derived from the role assignment, read-only.
        $this->actingAs($director, 'sanctum')
            ->getJson('/api/v1/workspace/my-assignment')
            ->assertOk()
            ->assertJsonPath('assignment.source', 'role_assignment')
            ->assertJsonPath('assignment.scope_type', 'org_unit');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. EXPIRED / REVOKED ASSIGNMENTS STOP ACCESS
    // ══════════════════════════════════════════════════════════════════════════

    public function test_expired_role_assignment_does_not_grant_access(): void
    {
        $user = $this->createUserWithoutOperationalAccess();

        UserRoleAssignment::create([
            'user_id'        => $user->id,
            'system_role_id' => $this->roleSiteCoordinator->id,
            'scope_type'     => 'site',
            'scoped_site_id' => $this->siteA1->id,
            'valid_from'     => now()->subYear()->toDateString(),
            'valid_until'    => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/people')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance-registers')->assertStatus(403);
    }

    public function test_revoked_role_assignment_removes_access(): void
    {
        $user = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/people')->assertOk();

        // Revoke every assignment, then force the authorization cache to be
        // re-resolved (the service is a scoped singleton).
        UserRoleAssignment::where('user_id', $user->id)->delete();
        $this->app->forgetScopedInstances();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/people')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance-registers')->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5. ASSIGNMENT HISTORY IS PRESERVED
    // ══════════════════════════════════════════════════════════════════════════

    public function test_ending_a_role_assignment_removes_access_but_keeps_the_record(): void
    {
        $user = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance-registers')->assertOk();

        // Ending an assignment sets an end date — the row is never deleted.
        UserRoleAssignment::where('user_id', $user->id)
            ->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->app->forgetScopedInstances();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance-registers')->assertStatus(403);

        // The historical record survives.
        $this->assertDatabaseHas('user_role_assignments', ['user_id' => $user->id]);
    }

    public function test_completed_deployment_is_retained_but_not_current(): void
    {
        $user   = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);
        $person = $user->person;

        $deployment = CampaignAssignment::create([
            'campaign_id'         => $this->campaignA->id,
            'person_id'           => $person->id,
            'mission_site_id'     => $this->siteA1->id,
            'assigned_team_id'    => $this->teamA1->id,
            'status'              => 'completed',
            'assigned_by_user_id' => $user->id,
        ]);

        // The finished deployment is not surfaced as the current assignment;
        // the workspace falls back to the (still active) role assignment.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workspace/my-assignment')
            ->assertOk()
            ->assertJsonPath('assignment.source', 'role_assignment');

        // ...but the deployment record itself is preserved for history.
        $this->assertDatabaseHas('campaign_assignments', ['id' => $deployment->id]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 6. DUPLICATE CHECKING RESPECTS AUTHENTICATION AND SCOPE
    // ══════════════════════════════════════════════════════════════════════════

    public function test_duplicate_check_requires_authentication(): void
    {
        $this->getJson('/api/v1/people/check-duplicates?first_name=John&last_name=Doe')
            ->assertStatus(401);
    }

    public function test_duplicate_check_is_denied_without_operational_assignment(): void
    {
        $user = $this->createUserWithoutOperationalAccess();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/people/check-duplicates?first_name=John&last_name=Doe')
            ->assertStatus(403);
    }

    public function test_duplicate_check_only_returns_people_within_scope(): void
    {
        $user  = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $other = $this->createUserWithoutOperationalAccess();

        // Registered by the caller → visible.
        $inScope = Person::factory()->create([
            'first_name' => 'Amos',
            'last_name'  => 'Zulu',
            'phone'      => '0700111222',
            'registered_by_user_id' => $user->id,
        ]);

        // Registered by somebody else and linked to no scope the caller holds
        // → must never be disclosed.
        $outOfScope = Person::factory()->create([
            'first_name' => 'Amos',
            'last_name'  => 'Yankee',
            'phone'      => '0700333444',
            'registered_by_user_id' => $other->id,
        ]);

        // The in-scope person is found.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/people/check-duplicates?first_name=Amos&last_name=Zulu')
            ->assertOk()
            ->assertJsonPath('count', 1);

        // The out-of-scope person is not disclosed by name...
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/people/check-duplicates?first_name=Amos&last_name=Yankee')
            ->assertOk()
            ->assertJsonPath('count', 0);

        // ...nor by phone (the phone clause must not escape the scope filter).
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/people/check-duplicates?first_name=Nobody&last_name=Nobody&phone=0700333444')
            ->assertOk()
            ->assertJsonPath('count', 0);

        // Sanity check: the records really do exist.
        $this->assertDatabaseHas('people', ['id' => $inScope->id]);
        $this->assertDatabaseHas('people', ['id' => $outOfScope->id]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 7. MISSING OPTIONAL PARAMETERS ARE ACCEPTED
    // ══════════════════════════════════════════════════════════════════════════

    public function test_optional_query_parameters_may_be_omitted(): void
    {
        $user = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        // None of these send campaign_id / mission_site_id. A missing optional
        // parameter must be treated as absent, never as an invalid value.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/people')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/people?search=John')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance-registers')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/outreach-contacts')->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/people/check-duplicates?first_name=John&last_name=Doe')
            ->assertOk();
    }

    public function test_supplying_an_explicit_scope_filter_still_works(): void
    {
        $user = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        // When the parameters ARE supplied they must be honoured (the previous
        // serialization bug sent the literal string "undefined" instead).
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/people?campaign_id={$this->campaignA->id}&mission_site_id={$this->siteA1->id}")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/attendance-registers?campaign_id={$this->campaignA->id}&mission_site_id={$this->siteA1->id}")
            ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 8. LEGITIMATE CAMPAIGN-DEPLOYMENT ACCESS STILL WORKS
    // ══════════════════════════════════════════════════════════════════════════

    public function test_campaign_deployment_access_continues_to_work(): void
    {
        $user   = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $person = $user->person;

        CampaignAssignment::create([
            'campaign_id'         => $this->campaignA->id,
            'person_id'           => $person->id,
            'mission_site_id'     => $this->siteA1->id,
            'assigned_team_id'    => $this->teamA1->id,
            'status'              => 'confirmed',
            'assigned_by_user_id' => $user->id,
        ]);

        // A current deployment is the preferred workspace context.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workspace/my-assignment')
            ->assertOk()
            ->assertJsonPath('assignment.source', 'campaign_assignment')
            ->assertJsonPath('assignment.scope_type', 'team')
            ->assertJsonPath('assignment.campaign.id', $this->campaignA->id)
            ->assertJsonPath('assignment.site.id', $this->siteA1->id);

        // And the operational modules remain fully usable.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/people')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance-registers')->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FIXTURE BUILDERS
    // ══════════════════════════════════════════════════════════════════════════

    private function buildFixtures(): void
    {
        $typeUnion = OrgUnitType::factory()->create(['code' => 'union', 'label' => 'Union']);
        $typeConf  = OrgUnitType::factory()->create(['code' => 'conference', 'label' => 'Conference']);

        $this->uu = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeUnion->id,
            'name'             => 'Uganda Union',
        ]);
        $this->cuc = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeConf->id,
            'parent_id'        => $this->uu->id,
            'name'             => 'Central Uganda Conference',
        ]);

        $pt        = ProgrammeType::factory()->create(['code' => 'global_mission', 'label' => 'Global Mission']);
        $programme = Programme::factory()->create([
            'programme_type_id' => $pt->id,
            'owning_org_unit_id' => $this->uu->id,
        ]);

        $this->campaignA = Campaign::factory()->create([
            'programme_id'     => $programme->id,
            'lead_org_unit_id' => $this->cuc->id,
            'name'             => 'Campaign Alpha',
            'status'           => 'active',
        ]);
        $this->campaignB = Campaign::factory()->create([
            'programme_id'     => $programme->id,
            'lead_org_unit_id' => $this->cuc->id,
            'name'             => 'Campaign Beta',
            'status'           => 'active',
        ]);

        $this->siteA1 = MissionSite::factory()->create(['campaign_id' => $this->campaignA->id, 'name' => 'Site A1']);
        $this->siteA2 = MissionSite::factory()->create(['campaign_id' => $this->campaignA->id, 'name' => 'Site A2']);
        $this->siteB1 = MissionSite::factory()->create(['campaign_id' => $this->campaignB->id, 'name' => 'Site B1']);

        $teamType = TeamType::create([
            'code'      => 'field_team',
            'label'     => 'Field Team',
            'dimension' => 'operational',
            'is_active' => true,
        ]);

        $this->teamA1 = Team::factory()->create([
            'campaign_id'     => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'team_type_id'    => $teamType->id,
            'name'            => 'Team A1',
        ]);

        // The seeded missionary role: outreach/attendance only.
        $this->roleMissionary = $this->createRole('missionary', 'site', [
            'attendance.submit',
            'outcomes.record',
            'outreach-contacts.create',
            'outreach-contacts.edit',
            'outreach-contacts.view',
        ]);

        // A site-level coordinator with the full People/Fieldwork surface.
        $this->roleSiteCoordinator = $this->createRole('site_coordinator', 'site', [
            'sites.view', 'sites.edit',
            'assignments.view', 'assignments.manage',
            'attendance.submit', 'attendance.verify', 'attendance.view',
            'report.submit', 'report.view',
            'outcomes.record', 'outcomes.view',
            'people.create', 'people.edit', 'people.view',
            'outreach-contacts.create', 'outreach-contacts.edit', 'outreach-contacts.view',
        ]);

        // An organisational (org_unit) appointment.
        $this->roleDirector = $this->createRole('union_director', 'org_unit', [
            'org.view',
            'campaigns.view', 'campaigns.edit',
            'sites.view',
            'assignments.view', 'assignments.manage',
            'report.view', 'report.review',
            'attendance.view',
            'people.view',
            'outreach-contacts.view',
        ]);
    }

    private function createRole(string $code, string $scopeType, array $permCodes): SystemRole
    {
        $role = SystemRole::factory()->create([
            'code'       => $code,
            'label'      => ucwords(str_replace('_', ' ', $code)),
            'scope_type' => $scopeType,
        ]);

        $permIds = collect($permCodes)->map(function (string $pc) {
            return Permission::firstOrCreate(
                ['code' => $pc],
                ['label' => $pc, 'module' => explode('.', $pc)[0]]
            )->id;
        })->toArray();

        $role->permissions()->sync($permIds);

        return $role;
    }

    /**
     * A user with a Person profile but deliberately NO operational access —
     * no role assignment and no campaign deployment.
     */
    private function createUserWithoutOperationalAccess(): User
    {
        $user = User::factory()->create();
        Person::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function createUserWithSiteRole(SystemRole $role, MissionSite $site): User
    {
        $user = User::factory()->create();
        Person::factory()->create(['user_id' => $user->id]);

        UserRoleAssignment::create([
            'user_id'        => $user->id,
            'system_role_id' => $role->id,
            'scope_type'     => 'site',
            'scoped_site_id' => $site->id,
        ]);

        return $user;
    }

    private function createUserWithOrgUnitRole(SystemRole $role, OrgUnit $orgUnit): User
    {
        $user = User::factory()->create();
        Person::factory()->create(['user_id' => $user->id]);

        UserRoleAssignment::create([
            'user_id'            => $user->id,
            'system_role_id'     => $role->id,
            'scope_type'         => 'org_unit',
            'scoped_org_unit_id' => $orgUnit->id,
        ]);

        return $user;
    }

    private function createRegister(Campaign $campaign, MissionSite $site): AttendanceRegister
    {
        return AttendanceRegister::create([
            'campaign_id'     => $campaign->id,
            'mission_site_id' => $site->id,
            'register_name'   => 'Test Register',
            'register_date'   => now()->toDateString(),
            'register_type'   => 'meeting',
            'status'          => 'open',
        ]);
    }
}
