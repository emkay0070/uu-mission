<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\OutcomeType;
use App\Models\Permission;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OrgUnitScopeTest — verifies organizational-unit-scoped access.
 *
 * Scenario:
 *   Uganda Union (root)
 *   └── Central Uganda Conference (child)
 *       └── Lira District (grandchild)
 *
 *   Campaign Alpha has lead_org_unit = Central Uganda Conference.
 *   Campaign Beta  has lead_org_unit = a separate, unrelated org unit.
 *
 * Tests:
 *   1. A union_director scoped to Uganda Union can access Campaign Alpha
 *      (because CUC is a descendant of Uganda Union).
 *   2. The same director cannot access Campaign Beta (different hierarchy).
 *   3. A director scoped to CUC can access Campaign Alpha (direct match).
 *   4. A director scoped to Lira District CANNOT access Campaign Alpha
 *      (Lira District is a CHILD of CUC, not an ancestor — scope is "upward
 *       authorization", not downward delegation).
 *   5. An org_unit-scoped director cannot access a campaign in an
 *      entirely unrelated org unit.
 *
 * Also covers:
 *   - DB-driven outcome_type validation accepts active types from DB.
 *   - DB-driven outcome_type validation rejects unknown codes.
 *   - /ref/outcome-types endpoint returns active types.
 */
class OrgUnitScopeAndReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $ugandaUnion;
    private OrgUnit $cuc;          // Central Uganda Conference
    private OrgUnit $liraDistrict;
    private OrgUnit $unrelatedOrg;
    private Campaign $campaignAlpha; // lead = CUC
    private Campaign $campaignBeta;  // lead = unrelatedOrg
    private MissionSite $siteAlpha;
    private SystemRole $roleDirector;
    private SystemRole $roleCampaignLeader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ORG-UNIT SCOPE TESTS
    // ══════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function union_director_can_view_campaign_within_their_hierarchy(): void
    {
        // Uganda Union → CUC (descendant) → Campaign Alpha ✓
        $director = $this->createUserWithOrgUnitRole($this->roleDirector, $this->ugandaUnion);

        $this->actingAs($director, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignAlpha->id}")
             ->assertOk()
             ->assertJsonFragment(['id' => $this->campaignAlpha->id]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function union_director_cannot_view_campaign_outside_their_hierarchy(): void
    {
        // Uganda Union hierarchy does not contain unrelatedOrg
        $director = $this->createUserWithOrgUnitRole($this->roleDirector, $this->ugandaUnion);

        $this->actingAs($director, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignBeta->id}")
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function conference_director_can_view_campaign_led_by_their_conference(): void
    {
        // CUC-scoped director, Campaign Alpha's lead_org_unit = CUC (direct)
        $director = $this->createUserWithOrgUnitRole($this->roleDirector, $this->cuc);

        $this->actingAs($director, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignAlpha->id}")
             ->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function district_director_cannot_access_conference_campaign(): void
    {
        // Lira District is a child of CUC — org_unit scope is NOT delegated downward.
        // A district-level role does not inherit CUC's campaigns.
        $director = $this->createUserWithOrgUnitRole($this->roleDirector, $this->liraDistrict);

        $this->actingAs($director, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignAlpha->id}")
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function org_unit_director_cannot_list_sites_for_unrelated_campaign(): void
    {
        $director = $this->createUserWithOrgUnitRole($this->roleDirector, $this->ugandaUnion);

        $this->actingAs($director, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignBeta->id}/sites")
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function org_unit_scoped_user_can_view_stats_for_campaign_in_hierarchy(): void
    {
        $director = $this->createUserWithOrgUnitRole($this->roleDirector, $this->ugandaUnion);

        $this->actingAs($director, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignAlpha->id}/stats")
             ->assertOk()
             ->assertJsonFragment(['campaign_id' => $this->campaignAlpha->id]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function org_unit_director_can_list_sites_in_own_hierarchy_campaign(): void
    {
        $director = $this->createUserWithOrgUnitRole($this->roleDirector, $this->cuc);

        $this->actingAs($director, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignAlpha->id}/sites")
             ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // REFERENCE DATA — OUTCOME TYPES
    // ══════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function ref_outcome_types_returns_active_types(): void
    {
        OutcomeType::factory()->create(['code' => 'test_active',   'is_active' => true]);
        OutcomeType::factory()->create(['code' => 'test_inactive', 'is_active' => false]);

        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignAlpha);

        $response = $this->actingAs($leader, 'sanctum')
             ->getJson('/api/v1/ref/outcome-types')
             ->assertOk();

        $codes = collect($response->json())->pluck('code')->toArray();
        $this->assertContains('test_active', $codes);
        $this->assertNotContains('test_inactive', $codes);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function outcome_store_accepts_active_db_outcome_type(): void
    {
        $type   = OutcomeType::factory()->create(['code' => 'custom_type', 'is_active' => true]);
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignAlpha);

        $this->actingAs($leader, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignAlpha->id}/outcomes", [
                 'mission_site_id' => $this->siteAlpha->id,
                 'outcome_type'    => 'custom_type',
                 'outcome_date'    => now()->toDateString(),
                 'visitor_name'    => 'Test Person',
             ])
             ->assertStatus(201);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function outcome_store_rejects_unknown_outcome_type(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignAlpha);

        $this->actingAs($leader, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignAlpha->id}/outcomes", [
                 'mission_site_id' => $this->siteAlpha->id,
                 'outcome_type'    => 'completely_made_up_type',
                 'outcome_date'    => now()->toDateString(),
                 'visitor_name'    => 'Test Person',
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['outcome_type']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function outcome_store_rejects_inactive_outcome_type(): void
    {
        OutcomeType::factory()->create(['code' => 'deprecated_type', 'is_active' => false]);
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignAlpha);

        $this->actingAs($leader, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignAlpha->id}/outcomes", [
                 'mission_site_id' => $this->siteAlpha->id,
                 'outcome_type'    => 'deprecated_type',
                 'outcome_date'    => now()->toDateString(),
                 'visitor_name'    => 'Test Person',
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['outcome_type']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_stats_returns_outcome_counts_by_type(): void
    {
        // Create two active outcome types and one inactive
        OutcomeType::factory()->create(['code' => 'stat_type_a', 'is_active' => true,  'include_in_stats' => true,  'sort_order' => 5]);
        OutcomeType::factory()->create(['code' => 'stat_type_b', 'is_active' => true,  'include_in_stats' => false, 'sort_order' => 6]);
        OutcomeType::factory()->create(['code' => 'stat_type_c', 'is_active' => false, 'include_in_stats' => true,  'sort_order' => 7]);

        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignAlpha);

        $response = $this->actingAs($leader, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignAlpha->id}/stats")
             ->assertOk();

        $byType = $response->json('outcomes.by_type');

        // stat_type_a: active + include_in_stats → appears
        $this->assertArrayHasKey('stat_type_a', $byType);
        // stat_type_b: include_in_stats=false → does NOT appear
        $this->assertArrayNotHasKey('stat_type_b', $byType);
        // stat_type_c: inactive → does NOT appear
        $this->assertArrayNotHasKey('stat_type_c', $byType);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_transition_rejects_invalid_status(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignAlpha);

        $this->actingAs($leader, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignAlpha->id}/transition", ['to' => 'unknown_status'])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['to']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FIXTURE BUILDERS
    // ══════════════════════════════════════════════════════════════════════════

    private function buildFixtures(): void
    {
        $typeUnion    = OrgUnitType::factory()->create(['code' => 'union_t']);
        $typeConf     = OrgUnitType::factory()->create(['code' => 'conf_t']);
        $typeDist     = OrgUnitType::factory()->create(['code' => 'dist_t']);

        $this->ugandaUnion   = OrgUnit::factory()->create(['org_unit_type_id' => $typeUnion->id, 'parent_id' => null,                       'name' => 'Uganda Union']);
        $this->cuc           = OrgUnit::factory()->create(['org_unit_type_id' => $typeConf->id,  'parent_id' => $this->ugandaUnion->id,      'name' => 'CUC']);
        $this->liraDistrict  = OrgUnit::factory()->create(['org_unit_type_id' => $typeDist->id,  'parent_id' => $this->cuc->id,             'name' => 'Lira District']);
        $this->unrelatedOrg  = OrgUnit::factory()->create(['org_unit_type_id' => $typeConf->id,  'parent_id' => null,                       'name' => 'Unrelated Org']);

        $pt        = ProgrammeType::factory()->create(['code' => 'gm_ou']);
        $programme = Programme::factory()->create(['programme_type_id' => $pt->id, 'owning_org_unit_id' => $this->ugandaUnion->id]);

        $this->campaignAlpha = Campaign::factory()->create([
            'programme_id'     => $programme->id,
            'lead_org_unit_id' => $this->cuc->id,       // CUC leads Campaign Alpha
            'name'             => 'Alpha',
            'status'           => 'active',
        ]);
        $this->campaignBeta = Campaign::factory()->create([
            'programme_id'     => $programme->id,
            'lead_org_unit_id' => $this->unrelatedOrg->id, // different hierarchy
            'name'             => 'Beta',
            'status'           => 'active',
        ]);

        $this->siteAlpha = MissionSite::factory()->create([
            'campaign_id' => $this->campaignAlpha->id,
            'name'        => 'Alpha Site 1',
        ]);

        $this->roleDirector      = $this->createRole('union_director_ou', 'org_unit', $this->directorPerms());
        $this->roleCampaignLeader = $this->createRole('campaign_leader_ou', 'campaign', $this->leaderPerms());
    }

    private function createRole(string $code, string $scopeType, array $permCodes): SystemRole
    {
        $role = SystemRole::factory()->create(['code' => $code, 'scope_type' => $scopeType]);

        $permIds = collect($permCodes)->map(fn ($pc) =>
            Permission::firstOrCreate(['code' => $pc], ['label' => $pc, 'module' => explode('.', $pc)[0]])->id
        )->toArray();

        $role->permissions()->sync($permIds);
        return $role;
    }

    private function createUserWithOrgUnitRole(SystemRole $role, OrgUnit $orgUnit): User
    {
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id'            => $user->id,
            'system_role_id'     => $role->id,
            'scope_type'         => 'org_unit',
            'scoped_org_unit_id' => $orgUnit->id,
        ]);
        return $user;
    }

    private function createUserWithCampaignRole(SystemRole $role, Campaign $campaign): User
    {
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id'            => $user->id,
            'system_role_id'     => $role->id,
            'scope_type'         => 'campaign',
            'scoped_campaign_id' => $campaign->id,
        ]);
        return $user;
    }

    private function directorPerms(): array
    {
        return [
            'campaigns.view', 'campaigns.edit',
            'sites.view', 'sites.create', 'sites.edit',
            'assignments.view', 'assignments.manage',
            'report.view', 'report.submit', 'report.review',
            'outcomes.view', 'outcomes.record', 'outcomes.manage_followup',
        ];
    }

    private function leaderPerms(): array
    {
        return [
            'campaigns.view', 'campaigns.edit',
            'sites.view', 'sites.create', 'sites.edit',
            'assignments.view', 'assignments.manage',
            'report.view', 'report.submit', 'report.review',
            'outcomes.view', 'outcomes.record', 'outcomes.manage_followup',
        ];
    }
}
