<?php

namespace Tests\Feature;

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
use App\Models\TeamMember;
use App\Models\TeamType;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\PublishesRolePermissions;

/**
 * WorkspaceTest — verifies missionary workspace endpoints.
 *
 * Test coverage:
 *   1. Authenticated missionary with confirmed assignment receives correct data
 *   2. Arrived missionary still receives current assignment
 *   3. Missionary without active assignment receives empty response
 *   4. Completed/withdrawn/no-show assignments are not current
 *   5. Unauthenticated requests are rejected
 *   6. Missionaries cannot access each other's data
 *   7. Team responses include only active members
 */
class WorkspaceTest extends TestCase
{
    use PublishesRolePermissions;
    use RefreshDatabase;

    private OrgUnit $uu;

    private OrgUnit $cuc;

    private Campaign $campaign;

    private MissionSite $site;

    private Team $team;

    private SystemRole $roleMissionary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    // ── Test Case 1: Confirmed assignment ─────────────────────────────────────

    public function test_missionary_with_confirmed_assignment_receives_assignment_data(): void
    {
        $missionary = $this->createMissionaryWithAssignment('confirmed');

        $response = $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-assignment')
            ->assertOk();

        $response->assertJsonStructure([
            'assignment' => [
                'id',
                'status',
                'campaign' => ['id', 'name', 'status'],
                'site' => ['id', 'name', 'status'],
                'team' => ['id', 'name'],
            ],
        ]);

        $response->assertJsonFragment([
            'status' => 'confirmed',
            'id' => $this->campaign->id,
            'name' => $this->campaign->name,
        ]);
        $response->assertJsonFragment([
            'id' => $this->site->id,
            'name' => $this->site->name,
        ]);
        $response->assertJsonFragment([
            'id' => $this->team->id,
            'name' => $this->team->name,
        ]);
    }

    // ── Test Case 2: Arrived assignment ────────────────────────────────────────

    public function test_missionary_with_arrived_assignment_receives_current_assignment(): void
    {
        $missionary = $this->createMissionaryWithAssignment('arrived');

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-assignment')
            ->assertOk()
            ->assertJsonFragment(['status' => 'arrived']);
    }

    // ── Test Case 3: No active assignment ───────────────────────────────────────

    public function test_missionary_without_active_assignment_receives_empty_response(): void
    {
        // Truly no operational access: no deployment and no role assignment.
        $missionary = $this->createUserWithNoOperationalAccess();

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-assignment')
            ->assertOk()
            ->assertJson(['assignment' => null]);
    }

    public function test_missionary_with_no_assignment_receives_empty_response(): void
    {
        $missionary = $this->createUserWithNoOperationalAccess();

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-assignment')
            ->assertOk()
            ->assertJson(['assignment' => null]);
    }

    // ── Test Case 4: Terminal statuses not current ────────────────────────────

    public function test_completed_assignment_is_not_treated_as_current(): void
    {
        $missionary = $this->createMissionaryWithAssignment('completed');

        // The finished deployment is never surfaced as the current deployment;
        // the workspace falls back to the active role-assignment context.
        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-assignment')
            ->assertOk()
            ->assertJsonPath('assignment.source', 'role_assignment');
    }

    public function test_withdrew_assignment_is_not_treated_as_current(): void
    {
        $missionary = $this->createMissionaryWithAssignment('withdrew');

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-assignment')
            ->assertOk()
            ->assertJsonPath('assignment.source', 'role_assignment');
    }

    public function test_no_show_assignment_is_not_treated_as_current(): void
    {
        $missionary = $this->createMissionaryWithAssignment('no_show');

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-assignment')
            ->assertOk()
            ->assertJsonPath('assignment.source', 'role_assignment');
    }

    // ── Test Case 5: Unauthenticated requests ───────────────────────────────────

    public function test_unauthenticated_my_assignment_request_returns_401(): void
    {
        $this->getJson('/api/v1/workspace/my-assignment')->assertStatus(401);
    }

    public function test_unauthenticated_my_team_request_returns_401(): void
    {
        $this->getJson('/api/v1/workspace/my-team')->assertStatus(401);
    }

    // ── Test Case 6: Missionary isolation ──────────────────────────────────────

    public function test_missionary_cannot_access_another_missionarys_assignment(): void
    {
        $missionaryA = $this->createMissionaryWithAssignment('confirmed');
        $missionaryB = $this->createUserWithNoOperationalAccess();

        // Missionary B has no operational access, so should get null
        $this->actingAs($missionaryB, 'sanctum')
            ->getJson('/api/v1/workspace/my-assignment')
            ->assertOk()
            ->assertJson(['assignment' => null]);

        // Missionary B cannot access Missionary A's assignment by any means
        // (endpoint derives user from auth, no ID parameter to manipulate)
    }

    public function test_missionary_cannot_access_another_missionarys_team(): void
    {
        $missionaryA = $this->createMissionaryWithAssignment('confirmed');
        $missionaryB = $this->createUserWithNoOperationalAccess();

        // Missionary B has no team, so should get null
        $this->actingAs($missionaryB, 'sanctum')
            ->getJson('/api/v1/workspace/my-team')
            ->assertOk()
            ->assertJson(['team' => null]);
    }

    // ── Test Case 7: Team active members only ──────────────────────────────────

    public function test_team_response_includes_only_active_members(): void
    {
        $missionary = $this->createMissionaryWithAssignment('confirmed');

        // Add an active member
        $activeMember = Person::factory()->create();
        TeamMember::create([
            'team_id' => $this->team->id,
            'person_id' => $activeMember->id,
            'role_in_team' => 'member',
            'joined_on' => now()->subDays(10),
        ]);

        // Add an ended member
        $endedMember = Person::factory()->create();
        TeamMember::create([
            'team_id' => $this->team->id,
            'person_id' => $endedMember->id,
            'role_in_team' => 'member',
            'joined_on' => now()->subDays(30),
            'ended_on' => now()->subDays(5),
        ]);

        $response = $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-team')
            ->assertOk();

        $memberIds = collect($response->json('team.members'))->pluck('person.id')->toArray();

        $this->assertContains($activeMember->id, $memberIds);
        $this->assertNotContains($endedMember->id, $memberIds);
    }

    public function test_team_response_does_not_expose_unrelated_team_information(): void
    {
        $missionary = $this->createMissionaryWithAssignment('confirmed');

        // Create another team in the same campaign
        $otherTeam = Team::factory()->create([
            'campaign_id' => $this->campaign->id,
            'name' => 'Other Team',
        ]);

        // Add members to other team
        $otherMember = Person::factory()->create();
        TeamMember::create([
            'team_id' => $otherTeam->id,
            'person_id' => $otherMember->id,
            'role_in_team' => 'member',
            'joined_on' => now()->subDays(10),
        ]);

        $response = $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-team')
            ->assertOk();

        // Should only return the missionary's assigned team
        $response->assertJsonFragment(['id' => $this->team->id, 'name' => $this->team->name]);
        $response->assertJsonMissing(['id' => $otherTeam->id]);
    }

    // ── Additional: Team endpoint with assignment ─────────────────────────────

    public function test_missionary_with_team_receives_team_data(): void
    {
        $missionary = $this->createMissionaryWithAssignment('confirmed');

        $response = $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-team')
            ->assertOk();

        $response->assertJsonStructure([
            'team' => [
                'id',
                'name',
                'team_type',
                'members' => [
                    '*' => [
                        'id',
                        'role_in_team',
                        'joined_on',
                        'person' => ['id', 'first_name', 'last_name', 'full_name', 'gender'],
                    ],
                ],
            ],
        ]);

        $response->assertJsonFragment(['id' => $this->team->id, 'name' => $this->team->name]);
    }

    public function test_missionary_without_team_receives_empty_team_response(): void
    {
        // Create assignment without team
        $missionary = $this->createMissionaryWithoutAssignment();
        $person = $missionary->person;

        CampaignAssignment::create([
            'campaign_id' => $this->campaign->id,
            'person_id' => $person->id,
            'mission_site_id' => $this->site->id,
            'assigned_team_id' => null, // No team
            'status' => 'confirmed',
            'assigned_by_user_id' => $missionary->id,
        ]);

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-team')
            ->assertOk()
            ->assertJson(['team' => null]);
    }

    // ── Fixture builders ───────────────────────────────────────────────────────

    private function buildFixtures(): void
    {
        // Org hierarchy
        $typeUnion = OrgUnitType::factory()->create(['code' => 'union', 'label' => 'Union']);
        $typeConf = OrgUnitType::factory()->create(['code' => 'conference', 'label' => 'Conference']);

        $this->uu = OrgUnit::create([
            'org_unit_type_id' => $typeUnion->id,
            'name' => 'Uganda Union',
            'short_code' => 'UU',
            'country_code' => 'UG',
            'is_international' => false,
            'active_from' => now()->subYears(5),
        ]);
        $this->cuc = OrgUnit::create([
            'org_unit_type_id' => $typeConf->id,
            'parent_id' => $this->uu->id,
            'name' => 'Central Uganda Conference',
            'short_code' => 'CUC',
            'country_code' => 'UG',
            'is_international' => false,
            'active_from' => now()->subYears(5),
        ]);

        // Programme
        $pt = ProgrammeType::factory()->create(['code' => 'global_mission', 'label' => 'Global Mission']);
        $programme = Programme::factory()->create(['programme_type_id' => $pt->id, 'owning_org_unit_id' => $this->uu->id]);

        // Campaign
        $this->campaign = Campaign::factory()->create([
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $this->cuc->id,
            'name' => 'Test Campaign',
            'status' => 'active',
        ]);

        // Site
        $this->site = MissionSite::factory()->create([
            'campaign_id' => $this->campaign->id,
            'name' => 'Test Site',
            'status' => 'active',
        ]);

        // Team
        $teamType = TeamType::create(['code' => 'field_team', 'label' => 'Field Team', 'dimension' => 'operational', 'is_active' => true]);
        $this->team = Team::factory()->create([
            'campaign_id' => $this->campaign->id,
            'mission_site_id' => $this->site->id,
            'team_type_id' => $teamType->id,
            'name' => 'Test Team',
        ]);

        // Role
        $this->roleMissionary = $this->createRole('missionary', 'campaign', [
            'attendance.submit',
            'outcomes.record',
            'people.view',
            'assignments.view',
        ]);
    }

    private function createMissionaryWithAssignment(string $status): User
    {
        $user = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $user->id]);

        CampaignAssignment::create([
            'campaign_id' => $this->campaign->id,
            'person_id' => $person->id,
            'mission_site_id' => $this->site->id,
            'assigned_team_id' => $this->team->id,
            'status' => $status,
            'assigned_by_user_id' => $user->id,
        ]);

        // Assign campaign role for authorization
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $this->roleMissionary->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $this->campaign->id,
        ]);

        return $user;
    }

    private function createMissionaryWithoutAssignment(): User
    {
        $user = User::factory()->create();
        Person::factory()->create(['user_id' => $user->id]);

        // Assign campaign role for authorization
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $this->roleMissionary->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $this->campaign->id,
        ]);

        return $user;
    }

    /**
     * A user with a Person profile but no operational access whatsoever —
     * no campaign deployment and no role assignment.
     *
     * The workspace context is now derived from role assignments, so only a
     * user in this state produces a genuinely empty assignment/team response.
     */
    private function createUserWithNoOperationalAccess(): User
    {
        $user = User::factory()->create();
        Person::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function createRole(string $code, string $scopeType, array $permCodes): SystemRole
    {
        $role = SystemRole::factory()->create([
            'code' => $code,
            'label' => ucwords(str_replace('_', ' ', $code)),
            'scope_type' => $scopeType,
        ]);

        $permIds = collect($permCodes)->map(function ($pc) {
            return Permission::firstOrCreate(['code' => $pc], ['label' => $pc, 'module' => explode('.', $pc)[0]])->id;
        })->toArray();

        $this->publishRolePermissions($role, $permIds);

        return $role;
    }
}
